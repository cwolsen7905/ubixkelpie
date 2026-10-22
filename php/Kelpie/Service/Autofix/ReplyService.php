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
 * (the reviewer confirms). `propose()` posts a proposal and leaves the thread open: a diff for a fix, a reason for a
 * dismissal or a deferral. Never pushes.
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
            TriageClass::Defer        => 'defer** — %s' . "\n\nLeaving this open for the reviewer.",
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
     * Post a proposal on the thread: a fix as the diff Kelpie made, a dismissal or a deferral as its reason
     *
     * Nothing is pushed or resolved; the thread stays open for the person.
     *
     * @param string      $project      The project ID or URL-encodable path
     * @param int         $mrIid        The MR IID
     * @param string      $discussionId The discussion ID
     * @param TriageClass $kind         Fix, NotAnIssue (a dismissal) or Defer
     * @param string      $headSha      The head the proposal was made at
     * @param string      $text         The summary of the fix, or the reason
     * @param string      $diff         The diff git wrote, for a fix
     *
     * @return ?int The new note's ID, null when the reply failed or the kind is not a proposal
     */
    public function propose(string $project, int $mrIid, string $discussionId, TriageClass $kind, string $headSha, string $text, string $diff = ''): ?int
    {
        $text = $this->oneLine($text);
        $text = $text === '' ? 'no reason given.' : $text;
        $head = '`' . mb_substr($headSha, 0, 12) . '`';

        $lead = match ($kind) {
            TriageClass::Fix   => 'Kelpie proposes this change, which applies cleanly to ' . $head . ': ' . $text . "\n\n" . $this->fenced($diff, 'diff') . "\n\nI have not pushed anything.",
            TriageClass::NotAnIssue => 'Kelpie proposes dismissing this: ' . $text . "\n\nI have not resolved the thread; that is yours to do.",
            TriageClass::Defer => 'Kelpie proposes deferring this: ' . $text . "\n\nI have not resolved the thread; that is yours to do.",
            default            => null,
        };

        if ($lead === null) {
            return null;
        }

        $body = sprintf("%s\n\n<!-- kelpie:reply thread=%s class=%s head=%s -->", $lead, $discussionId, $kind->value, $headSha);
        $note = $this->writer->reply($project, $mrIid, $discussionId, $body);
        if ($note !== null) {
            $this->logger->info('Autofix proposal posted', ['class' => $kind->value, 'discussion_id' => $discussionId, 'mr_iid' => $mrIid]);
        }

        return $note;
    }

    /**
     * Code in a fence long enough that the text cannot close it, byte for byte (a diff must still apply)
     *
     * @param string $code     The text
     * @param string $language The fence language
     *
     * @return string
     */
    private function fenced(string $code, string $language): string
    {
        $longest = 0;
        if (preg_match_all('/`+/', $code, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }
        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence . $language . "\n" . rtrim($code) . "\n" . $fence;
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
