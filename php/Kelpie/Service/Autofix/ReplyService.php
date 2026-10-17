<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\DiscussionNote;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\GitLab\DiscussionWriter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Answers a triaged thread: the not-safe path (design section 5) and the resolution comment (section 4)
 *
 * `needs_human` gets a reply and the `kelpie::needs-human` label on the MR; `not_an_issue` gets a
 * reply; `already_fixed` gets a reply and the thread is resolved. A `fix` verdict is left silent here:
 * the fixer runner calls `fixed()` once the commit is pushed, and that reply never resolves the thread
 * (the reviewer confirms). Never pushes.
 *
 * @see \Kelpie\Tests\Service\Autofix\ReplyServiceTest PHPUnit test case
 */
final class ReplyService
{
    public const string LABEL = 'kelpie::needs-human';

    private const string MARKER = '<!-- kelpie:';

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
     * Whether Kelpie already answered the thread and nobody has written since
     *
     * @param DiscussionNote[] $notes        The thread's notes, oldest first
     * @param string           $discussionId The discussion ID
     *
     * @return bool
     */
    public function isAnswered(array $notes, string $discussionId): bool
    {
        $marker   = self::MARKER . 'reply thread=' . $discussionId . ' ';
        $answered = false;

        foreach ($notes as $note) {
            if (str_contains($note->body, $marker)) {
                $answered = true;
            } elseif ($answered && !str_contains($note->body, self::MARKER)) {
                $answered = false; // A new note arrived: the thread is triaged again
            }
        }

        return $answered;
    }

    /**
     * Act on a verdict
     *
     * @param string        $project      The project ID or URL-encodable path
     * @param int           $mrIid        The MR IID
     * @param string        $discussionId The discussion ID
     * @param TriageVerdict $verdict      The verdict
     *
     * @return ResponseOutcome Replied, Silent or Failed
     */
    public function respond(string $project, int $mrIid, string $discussionId, TriageVerdict $verdict): ResponseOutcome
    {
        $text = match ($verdict->class) {
            TriageClass::NeedsHuman   => 'needs human** — %s' . "\n\nI did not change any code.",
            TriageClass::NotAnIssue   => 'not an issue** — %s' . "\n\nLeaving this open for the reviewer.",
            TriageClass::AlreadyFixed => 'already fixed** — %s' . "\n\nResolving this thread; reopen it if I got it wrong.",
            TriageClass::Fix          => null,
        };

        if ($text === null) {
            return ResponseOutcome::Silent;
        }

        // Label first: a failed label leaves the thread unanswered, so the next run tries again.
        if ($verdict->class === TriageClass::NeedsHuman && !$this->writer->addLabel($project, $mrIid, self::LABEL)) {
            return ResponseOutcome::Failed;
        }

        $body = sprintf(
            "Kelpie triage: **%s\n\n<!-- kelpie:reply thread=%s class=%s -->",
            sprintf($text, $this->clean($verdict->reason)),
            $discussionId,
            $verdict->class->value,
        );

        if ($this->writer->reply($project, $mrIid, $discussionId, $body) === null) {
            return ResponseOutcome::Failed;
        }

        $this->logger->info('Autofix reply posted', ['class' => $verdict->class->value, 'discussion_id' => $discussionId, 'mr_iid' => $mrIid]);

        // The reply stays if the resolve fails; its marker stops a second reply on the next run.
        if ($verdict->class === TriageClass::AlreadyFixed && !$this->writer->resolve($project, $mrIid, $discussionId)) {
            $this->logger->warning('Autofix thread could not be resolved', ['discussion_id' => $discussionId, 'mr_iid' => $mrIid]);
        }

        return ResponseOutcome::Replied;
    }

    /**
     * Say a pushed fix is in the thread, with a link to the commit. The thread is left open.
     *
     * @param string $project      The project ID or URL-encodable path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     * @param string $commit       The pushed commit sha
     * @param string $commitUrl    The commit's web URL, empty when unknown
     * @param string $summary      One line on what changed
     *
     * @return ResponseOutcome Replied or Failed
     */
    public function fixed(string $project, int $mrIid, string $discussionId, string $commit, string $commitUrl, string $summary): ResponseOutcome
    {
        $where   = $commitUrl === '' ? '`' . mb_substr($commit, 0, 12) . '`' : $this->clean($commitUrl);
        $summary = $this->oneLine($summary);

        $body = sprintf(
            "Kelpie fixed this in %s: %s\n\nGate: phpcs, phpstan and phpunit passed. Leaving this thread open for you to confirm.\n\n<!-- kelpie:reply thread=%s class=%s -->",
            $where,
            $summary === '' ? 'see the commit.' : $summary,
            $discussionId,
            TriageClass::Fix->value,
        );

        if ($this->writer->reply($project, $mrIid, $discussionId, $body) === null) {
            return ResponseOutcome::Failed;
        }

        $this->logger->info('Autofix fix reply posted', ['commit' => $commit, 'discussion_id' => $discussionId, 'mr_iid' => $mrIid]);

        return ResponseOutcome::Replied;
    }

    /**
     * One cleaned line, at most 200 characters, ending in a full stop
     *
     * @param string $text Model text
     *
     * @return string
     */
    private function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $this->clean($text)));
        if (mb_strlen($line) > 200) {
            $line = rtrim(mb_substr($line, 0, 199)) . '…';
        }

        return $line === '' || str_ends_with($line, '.') || str_ends_with($line, '…') ? $line : $line . '.';
    }

    /**
     * Make model text safe to echo into a note: no hidden markers, no mentions
     *
     * @param string $reason The model's reason
     *
     * @return string
     */
    private function clean(string $reason): string
    {
        return str_replace(['<!--', '-->', '@'], ['', '', "@\u{200B}"], $reason);
    }
}
