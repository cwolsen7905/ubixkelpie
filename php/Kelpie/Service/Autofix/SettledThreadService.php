<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\DiscussionNote;
use Kelpie\DataTransferObject\ThreadHistory;
use Kelpie\Enum\SettleReason;
use Kelpie\GitLab\DiscussionReader;
use Kelpie\GitLab\DiscussionWriter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Resolve the threads of an MR that are settled, so nobody has to clear them by hand
 *
 * A thread is settled when it has a reply (never just its first note) and one of these holds:
 * - fixed and pushed: a reply says `Fixed` (Kelpie's push-mode reply) or "fixed in <sha>" and names a commit the MR carries;
 * - dismissed with a reason: its last reply says "dismissed" and gives a reason;
 * - it is Kelpie's own triage summary and every thread it covers is resolved.
 *
 * Only the bot, a configured agent or the person who opened the thread can settle it; anyone else's "dismissed" or "fixed in"
 * is a comment. It never resolves a thread with a note from anyone but the bot or the fixer after the fix, nor one whose deciding note
 * ends in a question: that is a person still asking. A summary counts only when the bot account wrote it, since a marker
 * is text anyone can type. Every resolve is logged with its reason; a failed one is logged and left for the next pass.
 * With `$apply` false nothing is written: the lines say what would be resolved. It is run for an MR on each job the worker
 * takes (the Note Hook's job among them) and by the sweep.
 *
 * @see \Kelpie\Tests\Service\Autofix\SettledThreadServiceTest PHPUnit test case
 */
final class SettledThreadService
{
    /**
     * How long a "working on" summary may stay before it is taken as left behind by a run that died
     */
    private const int WORKING_SECONDS = 3600;

    private const int MIN_REASON_WORDS = 3;

    /**
     * Constructor
     *
     * @param Logger           $logger      Logger
     * @param DiscussionReader $reader      Reads every thread of the MR
     * @param DiscussionWriter $writer      Reads the MR's commits and resolves threads
     * @param string           $botUsername The bot's username; only its summaries are Kelpie's
     * @param string[]         $agents      Usernames of the agents that fix threads (`KELPIE_SKIP_AUTHORS`); their word counts like the bot's
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionReader $reader,
        private readonly DiscussionWriter $writer,
        private readonly string $botUsername,
        private readonly array $agents = [],
    ) {
    }

    /**
     * Resolve what is settled on one MR
     *
     * @param int               $projectId The project ID
     * @param int               $mrIid     The MR IID
     * @param DateTimeImmutable $now       The current time
     * @param bool              $apply     Whether to resolve; false only reports
     *
     * @return list<string> One line per thread resolved (or that would be)
     */
    public function settle(int $projectId, int $mrIid, DateTimeImmutable $now, bool $apply = true): array
    {
        $threads = $this->reader->discussions($projectId, $mrIid);
        $open    = [];
        foreach ($threads as $thread) {
            if ($thread->resolvable && !$thread->resolved) {
                $open[] = $thread;
            }
        }
        if ($open === []) {
            return [];
        }

        // Summaries last, so a thread resolved a moment ago counts for the summary that lists it
        $regular   = [];
        $summaries = [];
        foreach ($open as $thread) {
            if ($this->isSummary($thread)) {
                $summaries[] = $thread;
            } else {
                $regular[] = $thread;
            }
        }

        $shas  = null;
        $lines = [];
        $done  = [];
        foreach ([...$regular, ...$summaries] as $thread) {
            if ($this->isSummary($thread)) {
                $reason = $this->summarySettled($thread, $threads, $done, $now) ? SettleReason::SummarySettled : null;
            } else {
                $shas ??= $this->writer->commitShas((string) $projectId, $mrIid) ?? [];
                $reason = $this->threadSettled($thread, $shas);
            }
            if ($reason === null) {
                continue;
            }

            $line = sprintf('%d!%d %s  %s', $projectId, $mrIid, $thread->discussionId, ($apply ? 'resolved: ' : 'would resolve: ') . $reason->value);
            if ($apply && !$this->writer->resolve((string) $projectId, $mrIid, $thread->discussionId)) {
                $this->logger->warning('Settled thread could not be resolved', ['discussion_id' => $thread->discussionId, 'mr_iid' => $mrIid, 'project_id' => $projectId, 'reason' => $reason->value]);
                continue;
            }

            $done[] = $thread->discussionId;
            if ($apply) {
                $this->logger->info('Settled thread resolved', ['discussion_id' => $thread->discussionId, 'mr_iid' => $mrIid, 'project_id' => $projectId, 'reason' => $reason->value]);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Whether the thread is Kelpie's triage summary: the bot's own first note carries the summary marker
     *
     * @param ThreadHistory $thread The thread
     *
     * @return bool
     */
    private function isSummary(ThreadHistory $thread): bool
    {
        $first = $thread->notes[0];

        return $first->author === $this->botUsername && $this->marker($first->body) !== null;
    }

    /**
     * The summary marker's attributes, null when the body has none
     *
     * @param string $body The note body
     *
     * @return ?array<string, string>
     */
    private function marker(string $body): ?array
    {
        if (preg_match('/^' . preg_quote(TriageStatusService::MARKER, '/') . '((?: [A-Za-z0-9_.-]{1,24}=[A-Za-z0-9_.:+-]{0,64})*) ?-->$/m', $body, $match) !== 1) {
            return null;
        }

        $attributes = [];
        foreach (explode(' ', trim($match[1])) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key !== '') {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Whether a summary's threads are all resolved
     *
     * A summary that lists its threads waits for exactly those. An older one, or one too long to list them, waits
     * for every other thread of the MR. One still saying "working on" is left unless it is old.
     *
     * @param ThreadHistory     $summary The summary thread
     * @param ThreadHistory[]   $threads Every thread of the MR
     * @param string[]          $done    The threads resolved in this pass
     * @param DateTimeImmutable $now     The current time
     *
     * @return bool
     */
    private function summarySettled(ThreadHistory $summary, array $threads, array $done, DateTimeImmutable $now): bool
    {
        $first = $summary->notes[0];
        if (str_starts_with(ltrim($first->body), 'Kelpie triage is working') && $now->getTimestamp() - $first->createdAt->getTimestamp() < self::WORKING_SECONDS) {
            return false;
        }

        $attributes = $this->marker($first->body) ?? [];
        $listed     = [];
        foreach ($attributes as $key => $value) {
            if (preg_match('/^t\d$/', $key) === 1) {
                $listed = [...$listed, ...explode('.', $value)];
            }
        }

        $listed  = array_values(array_diff($listed, ['']));
        $partial = !isset($attributes['n']) || $listed === [] || (int) $attributes['n'] > count($listed);

        foreach ($threads as $thread) {
            if ($thread === $summary || !$thread->resolvable || $thread->resolved || in_array($thread->discussionId, $done, true) || $this->isSummary($thread)) {
                continue;
            }
            if ($partial) {
                return false;
            }
            foreach ($listed as $id) {
                if (str_starts_with($thread->discussionId, $id)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether a thread's own notes settle it, and why
     *
     * @param ThreadHistory $thread The thread
     * @param string[]      $shas   The commits the MR carries
     *
     * @return ?SettleReason Null when it is not settled
     */
    private function threadSettled(ThreadHistory $thread, array $shas): ?SettleReason
    {
        $notes = $thread->notes;
        if (count($notes) < 2) {
            return null;
        }

        $last = $notes[count($notes) - 1];
        if ($this->isQuestion($last->body)) {
            return null;
        }
        if ($this->dismissal($last, $thread)) {
            return SettleReason::Dismissed;
        }

        // The latest fix note decides; whatever follows it must be the bot's or the fixer's own
        for ($index = count($notes) - 1; $index >= 1; --$index) {
            $note = $notes[$index];
            if (!$this->fixNamesCommit($note, $thread, $shas)) {
                continue;
            }
            if ($this->isQuestion($note->body)) {
                return null;
            }

            foreach (array_slice($notes, $index + 1) as $later) {
                if ($later->author !== $this->botUsername && $later->author !== $note->author) {
                    return null;
                }
            }

            return SettleReason::FixedPushed;
        }

        return null;
    }

    /**
     * Whether a note says it fixed the thing in a commit the MR carries
     *
     * @param DiscussionNote $note   The note
     * @param ThreadHistory  $thread The thread it is in
     * @param string[]       $shas   The commits the MR carries
     *
     * @return bool
     */
    private function fixNamesCommit(DiscussionNote $note, ThreadHistory $thread, array $shas): bool
    {
        if ($this->isProposal($note) || !$this->mayClose($note, $thread)) {
            return false;
        }

        // The bot says it as "Fixed: <commit>"; anyone else (an agent) may say "fixed in <sha>" anywhere
        $text = $this->visible($note->body);
        if (preg_match('/^\s*fixed\b/i', $text) !== 1 && ($note->author === $this->botUsername || preg_match('/\bfixed in\b/i', $text) !== 1)) {
            return false;
        }

        // A sha has a digit: words like "defaced" are all hex letters and are not commits
        if (preg_match_all('/\b(?=[0-9a-f]*[0-9])[0-9a-f]{7,40}\b/i', $text, $found) < 1) {
            return false;
        }

        foreach ($found[0] as $token) {
            foreach ($shas as $sha) {
                if (str_starts_with(strtolower($sha), strtolower($token))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a note dismisses the thread and says why
     *
     * The bot's own note must start "Dismissed:"; anyone else's may say "dismissed" in its first words. A proposal
     * to dismiss, which waits for a person, never counts.
     *
     * @param DiscussionNote $note   The note
     * @param ThreadHistory  $thread The thread it is in
     *
     * @return bool
     */
    private function dismissal(DiscussionNote $note, ThreadHistory $thread): bool
    {
        $text = $this->visible($note->body);
        if ($this->isProposal($note) || !$this->mayClose($note, $thread) || preg_match('/\b(?:not|never|isn\'t|wasn\'t|un)\s*dismissed\b/i', $text) === 1) {
            return false;
        }

        $pattern = $note->author === $this->botUsername ? '/^\s*dismissed\b:?(.*)$/is' : '/^\W*(?:\w+\W+){0,4}?dismissed\b(.*)$/is';
        if (preg_match($pattern, $text, $match) !== 1) {
            return false;
        }

        $reason = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $match[1]));

        return count(preg_split('/\s+/', $reason, -1, PREG_SPLIT_NO_EMPTY) ?: []) >= self::MIN_REASON_WORDS;
    }

    /**
     * Whether the note's author may settle the thread: the bot, a configured agent, or the person who opened it
     *
     * Anyone else's "dismissed" or "fixed in" is a comment, not the reviewer's agreement.
     *
     * @param DiscussionNote $note   The note
     * @param ThreadHistory  $thread The thread it is in
     *
     * @return bool
     */
    private function mayClose(DiscussionNote $note, ThreadHistory $thread): bool
    {
        return $note->author !== '' && ($note->author === $this->botUsername || $note->author === $thread->notes[0]->author || in_array($note->author, $this->agents, true));
    }

    /**
     * Whether the note is one of Kelpie's proposals (it carries the head it was made at), which waits for a person
     *
     * @param DiscussionNote $note The note
     *
     * @return bool
     */
    private function isProposal(DiscussionNote $note): bool
    {
        return preg_match('/<!-- kelpie:reply [^>]*\bhead=/', $note->body) === 1;
    }

    /**
     * Whether a note ends asking something
     *
     * @param string $body The note body
     *
     * @return bool
     */
    private function isQuestion(string $body): bool
    {
        return str_ends_with(rtrim($this->visible($body), " \t\n\r*_`)\"'"), '?');
    }

    /**
     * The text of a note a person reads: no hidden comments (Kelpie's markers carry thread ids, which look like shas)
     *
     * @param string $body The note body
     *
     * @return string
     */
    private function visible(string $body): string
    {
        return trim((string) preg_replace('/<!--.*?-->/s', '', $body));
    }
}
