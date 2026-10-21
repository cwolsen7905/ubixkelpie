<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\MergeRequestInfo;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\ThreadResult;
use Kelpie\DataTransferObject\TriageRun;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageStatus;
use Kelpie\GitLab\DiscussionWriter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Lets a person see from the MR list where triage is with a merge request
 *
 * A run calls `start()` when it picks up threads: the `triage: working` label replaces whatever an earlier
 * round left, one summary note says "working on N threads", and each thread gets 👀. `finish()` edits
 * that note into what happened per thread, takes `working` off and leaves exactly one of `triage: pushed`,
 * `triage: proposed` or `triage: needs you`. Labels are replaced, never stacked. Every GitLab failure is
 * logged and swallowed: the status is a courtesy and never stops the work.
 *
 * @see \Kelpie\Tests\Service\Autofix\TriageStatusServiceTest PHPUnit test case
 */
final class TriageStatusService
{
    private const string EYES = 'eyes';

    private const string MARKER = '<!-- kelpie:summary -->';

    /**
     * Constructor
     *
     * @param Logger           $logger Logger
     * @param DiscussionWriter $writer The GitLab writer
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
    ) {
    }

    /**
     * Whether triage must leave the MR alone for good: switched off for it, or a draft
     *
     * @param MergeRequestInfo $mr The merge request
     *
     * @return ?string Why, null when triage may go on
     */
    public function skipReason(MergeRequestInfo $mr): ?string
    {
        return match (true) {
            in_array(TriageStatus::OFF, $mr->labels, true) => 'triage is off for this merge request',
            $mr->draft                                     => 'the merge request is a draft',
            default                                        => null,
        };
    }

    /**
     * Whether another run is on the MR: it carries `triage: working` or the label older versions used
     *
     * @param MergeRequestInfo $mr The merge request
     *
     * @return bool
     */
    public function isClaimed(MergeRequestInfo $mr): bool
    {
        return in_array(TriageStatus::Working->value, $mr->labels, true) || in_array(TriageStatus::LEGACY_CLAIM, $mr->labels, true);
    }

    /**
     * Mark the MR as being worked on
     *
     * @param string   $project The project ID or URL-encodable path
     * @param int      $mrIid   The MR IID
     * @param Thread[] $threads The threads this run picked up
     *
     * @return ?TriageRun Null when the summary note could not be posted (the labels are set anyway)
     */
    public function start(string $project, int $mrIid, array $threads): ?TriageRun
    {
        $colours = [];
        foreach (TriageStatus::cases() as $status) {
            $colours[$status->value] = $status->colour();
        }
        if (!$this->writer->ensureLabels($project, $colours)) {
            $this->logger->warning('Triage status labels could not be created', ['mr_iid' => $mrIid]);
        }

        $stale = [TriageStatus::LEGACY_CLAIM];
        foreach (TriageStatus::outcomes() as $status) {
            $stale[] = $status->value;
        }
        if (!$this->writer->changeLabels($project, $mrIid, [TriageStatus::Working->value], $stale)) {
            $this->logger->warning('Triage working label could not be set', ['mr_iid' => $mrIid]);
        }

        foreach ($threads as $thread) {
            if (!$this->writer->awardEmoji($project, $mrIid, $thread->firstNoteId, self::EYES)) {
                $this->logger->info('Triage 👀 could not be placed', ['discussion_id' => $thread->discussionId, 'mr_iid' => $mrIid]);
            }
        }

        $count = count($threads);
        $note  = $this->writer->openNote($project, $mrIid, sprintf("Kelpie triage is working on %d %s.\n\n%s", $count, $count === 1 ? 'thread' : 'threads', self::MARKER));
        if ($note === null) {
            $this->logger->warning('Triage summary note could not be posted', ['mr_iid' => $mrIid]);

            return null;
        }

        return new TriageRun($note[0], $note[1], $threads);
    }

    /**
     * End the run: edit the summary note into the outcome, take `working` off and leave the one outcome label
     *
     * @param string                     $project The project ID or URL-encodable path
     * @param int                        $mrIid   The MR IID
     * @param ?TriageRun                 $run     What `start()` returned
     * @param array<string,ThreadResult> $results What was done, by discussion ID
     *
     * @return ?TriageStatus The label left, null when no thread ended up with a result
     */
    public function finish(string $project, int $mrIid, ?TriageRun $run, array $results): ?TriageStatus
    {
        $status = null;
        foreach ($results as $result) {
            if ($status === null || $result->status->rank() > $status->rank()) {
                $status = $result->status;
            }
        }

        // With nothing done there is nothing to report: back to no label rather than a false "pushed".
        $add = $status === null ? [] : [$status->value];
        if (!$this->writer->changeLabels($project, $mrIid, $add, [TriageStatus::Working->value])) {
            $this->logger->warning('Triage status label could not be set', ['mr_iid' => $mrIid]);
        }

        if ($run !== null && !$this->writer->editNote($project, $mrIid, $run->discussionId, $run->noteId, $this->summary($run, $results, $status))) {
            $this->logger->warning('Triage summary note could not be edited', ['mr_iid' => $mrIid]);
        }

        return $status;
    }

    /**
     * What a verdict that was answered on the thread comes to
     *
     * @param TriageVerdict   $verdict The verdict
     * @param ResponseOutcome $replied What the reply step did
     *
     * @return ThreadResult
     */
    public function resultFor(TriageVerdict $verdict, ResponseOutcome $replied): ThreadResult
    {
        $reason = $this->line($verdict->reason);

        if ($replied === ResponseOutcome::Failed) {
            return new ThreadResult(TriageStatus::NeedsYou, 'could not answer: yours' . ($reason === '' ? '.' : ' — the reply failed (' . $reason . ')'));
        }

        return match ($verdict->class) {
            TriageClass::NeedsHuman   => new ThreadResult(TriageStatus::NeedsYou, 'could not answer: yours — ' . $reason),
            TriageClass::NotAnIssue   => new ThreadResult(TriageStatus::Proposed, 'proposed leaving it: ' . $reason),
            TriageClass::AlreadyFixed => new ThreadResult(TriageStatus::Pushed, 'already fixed, resolved — ' . $reason),
            TriageClass::Fix          => new ThreadResult(TriageStatus::Proposed, 'a fix is warranted, not applied here — ' . $reason),
        };
    }

    /**
     * The summary note's final body
     *
     * @param TriageRun                  $run     The run
     * @param array<string,ThreadResult> $results What was done, by discussion ID
     * @param ?TriageStatus              $status  The label left
     *
     * @return string
     */
    private function summary(TriageRun $run, array $results, ?TriageStatus $status): string
    {
        $lines = [];
        foreach ($run->threads as $thread) {
            $where   = $thread->filePath === null ? 'thread ' . mb_substr($thread->discussionId, 0, 8) : $thread->filePath . ($thread->line === null ? '' : ':' . $thread->line);
            $result  = $results[$thread->discussionId] ?? null;
            $lines[] = sprintf('- `%s`: %s', $where, $result === null ? 'left for now' : $result->text);
        }

        $count = count($run->threads);

        return sprintf(
            "Kelpie triage looked at %d %s: **%s**.\n\n%s\n\n%s",
            $count,
            $count === 1 ? 'thread' : 'threads',
            $status === null ? 'nothing to do' : substr($status->value, strlen('triage: ')),
            implode("\n", $lines),
            self::MARKER,
        );
    }

    /**
     * Model text made safe for a note: one line, no hidden markers, no mentions
     *
     * @param string $text The text
     *
     * @return string
     */
    private function line(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', str_replace(['<!--', '-->', '@'], ['', '', "@\u{200B}"], $text)));

        return mb_strlen($line) > 200 ? rtrim(mb_substr($line, 0, 199)) . '…' : $line;
    }
}
