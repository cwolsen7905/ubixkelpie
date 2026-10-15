<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

use Kelpie\DataTransferObject\DiscussionNote;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\GitLab\DiscussionWriter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Answers a triaged thread: the not-safe path (design section 5)
 *
 * `needs_human` gets a reply and the `kelpie::needs-human` label on the MR; `not_an_issue` gets a
 * reply. `fix` and `already_fixed` are left silent until the fixer and the resolve step exist.
 * Never pushes, never resolves.
 *
 * @see \Kelpie\Tests\Autofix\ReplyServiceTest PHPUnit test case
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
            TriageClass::NeedsHuman  => 'needs human** — %s' . "\n\nI did not change any code.",
            TriageClass::NotAnIssue  => 'not an issue** — %s' . "\n\nLeaving this open for the reviewer.",
            TriageClass::Fix, TriageClass::AlreadyFixed => null,
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

        return ResponseOutcome::Replied;
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
