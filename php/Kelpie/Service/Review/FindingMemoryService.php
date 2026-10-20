<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\DataTransferObject\Review\ThreadMemory;
use Kelpie\DataTransferObject\Review\ThreadRow;
use Psr\Log\LoggerInterface as Logger;

/**
 * What the review remembers of a merge request: its finding threads, their state, its summary note, and how a new finding matches them
 *
 * A finding thread carries `<!-- ai-review:fp=<id> sha=<commit> -->`; the same marker as the shell review, so
 * threads from either engine are read alike. A thread is open when nobody resolved it, dismissed when it was
 * resolved with a person's reply starting `Dismissed:`, and otherwise ignored (resolved means fixed).
 *
 * @see \Kelpie\Tests\Service\Review\FindingMemoryServiceTest PHPUnit test case
 */
final class FindingMemoryService
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first; the memory has nothing to log)
    ) {
    }

    /**
     * Read a merge request's discussions
     *
     * @param ReviewSettings $settings    The settings
     * @param mixed[]        $discussions The discussions as GitLab returns them
     * @param int            $botId       The review token's user ID, 0 when unknown
     *
     * @return ThreadMemory
     */
    public function read(ReviewSettings $settings, array $discussions, int $botId): ThreadMemory
    {
        $open      = [];
        $dismissed = [];
        $summary   = [null, 0, ''];

        foreach ($discussions as $discussion) {
            $notes = is_array($discussion) && is_array($discussion['notes'] ?? null) ? array_values(array_filter($discussion['notes'], 'is_array')) : [];
            if ($notes === []) {
                continue;
            }

            foreach ($notes as $note) {
                $body = is_string($note['body'] ?? null) ? $note['body'] : '';
                if (
                    $summary[0] === null && str_starts_with($body, $settings->summaryMarker) && is_int($note['id'] ?? null)
                    && preg_match('~round=(\d+) sha=([0-9a-f]+)~', $body, $m) === 1
                ) {
                    $summary = [$note['id'], (int) $m[1], $m[2]];
                }
            }

            $first = is_string($notes[0]['body'] ?? null) ? $notes[0]['body'] : '';
            if (!str_contains($first, '<!-- ai-review:fp=')) {
                continue;
            }

            $row = $this->row($notes[0], $first);
            if ($row === null) {
                continue;
            }
            $state = $this->state($notes, $botId);
            if ($state === 'open') {
                $open[$row->id . '|' . $row->file . '|' . $row->title] = $row;
            } elseif ($state === 'dismissed') {
                $dismissed[$row->id . '|' . $row->file . '|' . $row->title] = $row;
            }
        }

        return new ThreadMemory(array_values($open), array_values($dismissed), $summary[0], $summary[1], $summary[2]);
    }

    /**
     * Whether a person dismissed this finding: by id, or by file and title when the model reworded the slug
     *
     * @param ThreadMemory $memory  The memory
     * @param Finding      $finding The finding
     *
     * @return bool
     */
    public function isDismissed(ThreadMemory $memory, Finding $finding): bool
    {
        return $this->matches($memory->dismissed, $finding);
    }

    /**
     * Whether a thread for this finding is open, by id or by file and title
     *
     * @param ThreadMemory $memory  The memory
     * @param Finding      $finding The finding
     *
     * @return bool
     */
    public function isOpen(ThreadMemory $memory, Finding $finding): bool
    {
        return $this->matches($memory->open, $finding);
    }

    /**
     * Whether an open thread already points at this finding's line: the same defect, reworded
     *
     * @param ThreadMemory $memory  The memory
     * @param Finding      $finding The finding
     *
     * @return bool
     */
    public function coversLine(ThreadMemory $memory, Finding $finding): bool
    {
        foreach ($memory->open as $row) {
            if ($row->line > 0 && $row->file === $finding->file && $row->line === $finding->line) {
                return true;
            }
        }

        return false;
    }

    /**
     * The open threads as the model is told of them, in words
     *
     * @param ThreadMemory $memory The memory
     *
     * @return string
     */
    public function raisedList(ThreadMemory $memory): string
    {
        $out = '';
        foreach ($memory->open as $row) {
            $out .= sprintf("- %s: %s (open)\n", $row->file, $row->title);
        }
        foreach ($memory->dismissed as $row) {
            $out .= sprintf("- %s: %s (dismissed by a person; do not raise again)\n", $row->file, $row->title);
        }

        return $out;
    }

    /**
     * @param ThreadRow[] $rows    The rows
     * @param Finding     $finding The finding
     *
     * @return bool
     */
    private function matches(array $rows, Finding $finding): bool
    {
        $key = $finding->file . '|' . mb_strtolower($finding->title);
        foreach ($rows as $row) {
            if ($row->id === $finding->id || ($row->title !== '' && $row->file . '|' . mb_strtolower($row->title) === $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<mixed>> $notes The discussion's notes
     * @param int                $botId The review token's user ID
     *
     * @return string open, dismissed or resolved
     */
    private function state(array $notes, int $botId): string
    {
        $resolved = false;
        foreach ($notes as $note) {
            $resolved = $resolved || ($note['resolved'] ?? false) === true;
        }
        if (!$resolved) {
            return 'open';
        }

        foreach (array_slice($notes, 1) as $note) {
            $authorId = is_array($note['author'] ?? null) && is_int($note['author']['id'] ?? null) ? $note['author']['id'] : 0;
            $body     = is_string($note['body'] ?? null) ? $note['body'] : '';
            if ($authorId !== $botId && preg_match('~^\s*Dismissed:~i', $body) === 1) {
                return 'dismissed';
            }
        }

        return 'resolved';
    }

    /**
     * @param array<mixed> $note  The thread's first note
     * @param string       $first Its body
     *
     * @return ?ThreadRow
     */
    private function row(array $note, string $first): ?ThreadRow
    {
        if (preg_match('~ai-review:fp=([0-9a-f]+)~', $first, $m) !== 1) {
            return null;
        }
        $id = $m[1];

        $file  = preg_match('~`([^`:]+):\d+`~', $first, $f) === 1 ? $f[1] : '-';
        $title = preg_match('~\): ([^\n]+)~', $first, $t) === 1 ? $t[1] : $id;
        $line  = preg_match('~`[^`:]+:(\d+)`~', $first, $l) === 1 ? (int) $l[1] : 0;
        $sha   = preg_match('~ai-review:fp=[0-9a-f]+ sha=([0-9a-f]+)~', $first, $s) === 1 ? $s[1] : '';
        if ($sha === '' && is_array($note['position'] ?? null) && is_string($note['position']['head_sha'] ?? null)) {
            $sha = $note['position']['head_sha'];
        }

        return new ThreadRow($id, $file, $title, $line, $sha);
    }
}
