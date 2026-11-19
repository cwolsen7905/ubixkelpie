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
 * A run calls `start()` when it picks up threads: the `kelpie::working` label replaces whatever an earlier
 * round left, one summary note says "working on N threads", and each thread gets 👀. `finish()` edits
 * that note into what happened per thread, takes `working` off and leaves exactly one of `kelpie::pushed`,
 * `kelpie::proposed` or `kelpie::needs-you`. Labels are replaced, never stacked. Every GitLab failure is
 * logged and swallowed: the status is a courtesy and never stops the work.
 *
 * @see \Kelpie\Tests\Service\Autofix\TriageStatusServiceTest PHPUnit test case
 */
final class TriageStatusService
{
    public const string MARKER = '<!-- kelpie:summary';

    private const string EYES = 'eyes';

    /**
     * Most threads whose IDs a summary's marker lists (the scanner allows five attributes of 64 characters; each holds five IDs)
     */
    private const int MARKED_THREADS = 25;

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
     * Whether another run is on the MR: it carries `kelpie::working` or the label older versions used
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

        if (!$this->writer->changeLabels($project, $mrIid, [TriageStatus::Working->value], TriageStatus::othersThan(TriageStatus::Working))) {
            $this->logger->warning('Triage working label could not be set', ['mr_iid' => $mrIid]);
        }

        foreach ($threads as $thread) {
            if (!$this->writer->awardEmoji($project, $mrIid, $thread->firstNoteId, self::EYES)) {
                $this->logger->info('Triage 👀 could not be placed', ['discussion_id' => $thread->discussionId, 'mr_iid' => $mrIid]);
            }
        }

        $count = count($threads);
        $note  = $this->writer->openNote($project, $mrIid, sprintf("Kelpie triage is working on %d %s.\n\n%s", $count, $count === 1 ? 'thread' : 'threads', $this->marker($threads)));
        if ($note === null) {
            $this->logger->warning('Triage summary note could not be posted', ['mr_iid' => $mrIid]);

            return null;
        }

        return new TriageRun($note, $threads);
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
        if (!$this->writer->changeLabels($project, $mrIid, $add, TriageStatus::othersThan($status))) {
            $this->logger->warning('Triage status label could not be set', ['mr_iid' => $mrIid]);
        }

        if ($run !== null && !$this->writer->editPlainNote($project, $mrIid, $run->noteId, $this->summary($run, $results, $status))) {
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
            TriageClass::Defer        => new ThreadResult(TriageStatus::Proposed, 'proposed deferring it: ' . $reason),
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
            $status === null ? 'nothing to do' : str_replace('-', ' ', substr($status->value, strlen('kelpie::'))),
            implode("\n", $lines),
            $this->marker($run->threads),
        );
    }

    /**
     * The marker that makes the note a summary and lists the threads it covers, so a later pass can resolve it once they are
     *
     * An ID is cut to ten characters, five to an attribute, to fit what the context scanner keeps. A run of more threads than
     * fit records the count too, so the reader knows the list is partial.
     *
     * @param Thread[] $threads The threads the run covers
     *
     * @return string
     */
    private function marker(array $threads): string
    {
        $ids = [];
        foreach (array_slice($threads, 0, self::MARKED_THREADS) as $thread) {
            $ids[] = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $thread->discussionId), 0, 10);
        }

        $attributes = '';
        foreach (array_chunk($ids, 5) as $index => $chunk) {
            $attributes .= sprintf(' t%d=%s', $index + 1, implode('.', $chunk));
        }

        return sprintf('%s n=%d%s -->', self::MARKER, count($threads), $attributes);
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
