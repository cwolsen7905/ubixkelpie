<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Kelpie\GitLab\GitlabClientFactory;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Resolves a merge request's open threads when an owner approves it
 *
 * The owner's Approve (or a thumbs-up on the merge request) means "I have read this, it may merge". With
 * "All threads must be resolved" on, an open review thread would still hold the merge, so Kelpie answers each
 * open thread with a note naming the approval and resolves it, as the Kelpie bot. The merge itself stays a
 * person's click: Kelpie never merges or arms a merge.
 *
 * Only a user listed in `KELPIE_APPROVAL_OWNERS` (comma-separated) counts; anyone else's approval is ignored, and
 * with the variable empty nothing is ever resolved (it fails closed). A thumbs-up taken back, an unapproval or any
 * other event is ignored.
 *
 * @see \Kelpie\Tests\Service\Gitlab\ApprovalResolveServiceTest PHPUnit test case
 */
final class ApprovalResolveService
{
    public const string MARKER = '<!-- kelpie:resolved-on-approval -->';

    private const int MAX_THREADS = 50;

    /**
     * Constructor
     *
     * @param Logger              $logger Logger
     * @param GitlabClientFactory $gitlab GitLab clients as the Kelpie bot
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
    ) {
    }

    /**
     * Handle a Merge Request Hook or an Emoji Hook
     *
     * @param string                   $event   The X-Gitlab-Event header value
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return string What happened; 'ignored: ...' when nothing was resolved
     */
    public function handle(string $event, array $payload): string
    {
        // Never let a GitLab failure here escape: the webhook must still re-run the sign-off job.
        try {
            return $this->resolve($event, $payload);
        } catch (Throwable $e) {
            $this->logger->error('Approval: resolving open threads failed', ['error' => $e->getMessage()]);

            return 'error: could not resolve open threads (' . $e->getMessage() . ')';
        }
    }

    /**
     * The work behind handle(), which may throw on a GitLab failure
     *
     * @param string                   $event   The X-Gitlab-Event header value
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return string What happened
     */
    private function resolve(string $event, array $payload): string
    {
        $approval = $this->approval(strtolower($event), $payload);
        if (is_string($approval)) {
            return $approval;
        }
        [$approver, $projectId, $mrIid] = $approval;

        $owners = $this->owners();
        if ($owners === []) {
            return 'ignored: KELPIE_APPROVAL_OWNERS is not set';
        }
        if (!in_array($approver, $owners, true)) {
            return 'ignored: ' . $approver . ' is not an approval owner';
        }

        $open     = [];
        $answered = [];
        foreach ($this->gitlab->reader()->discussions($projectId, $mrIid) as $thread) {
            if ($thread->resolvable && !$thread->resolved) {
                $open[] = $thread->discussionId;
                foreach ($thread->notes as $note) {
                    if (str_contains($note->body, self::MARKER)) {
                        $answered[$thread->discussionId] = true; // Replied on an earlier approval; only resolve
                    }
                }
            }
        }
        if ($open === []) {
            return 'nothing to resolve: no open threads';
        }

        $writer   = $this->gitlab->writer();
        $project  = (string) $projectId;
        $body     = sprintf("Resolved on owner approval: `%s` approved this merge request with this thread still open, so it is resolved to let the merge through. Reopen it if it still needs work.\n\n%s", $approver, self::MARKER);
        $resolved = 0;
        foreach (array_slice($open, 0, self::MAX_THREADS) as $discussionId) {
            $replied = isset($answered[$discussionId]) || $writer->reply($project, $mrIid, $discussionId, $body) !== null;
            if ($replied && $writer->resolve($project, $mrIid, $discussionId)) {
                $resolved++;
            }
        }
        if (count($open) > self::MAX_THREADS) {
            // A note on the MR would itself be an open discussion and hold the merge, so this is logged instead.
            $this->logger->warning('Approval: more open threads than one event resolves; approve again for the rest', ['project_id' => $projectId, 'mr_iid' => $mrIid, 'open' => count($open), 'cap' => self::MAX_THREADS]);
        }

        $this->logger->info('Approval: resolved open threads', ['project_id' => $projectId, 'mr_iid' => $mrIid, 'approver' => $approver, 'resolved' => $resolved, 'open' => count($open)]);

        $left = count($open) > self::MAX_THREADS ? sprintf('; %d left open past the cap of %d, approve again', count($open) - self::MAX_THREADS, self::MAX_THREADS) : '';

        return sprintf('resolved %d of %d open threads on approval by %s%s', $resolved, count($open), $approver, $left);
    }

    /**
     * The approver, project and MR of an owner sign-off event, or why the event is not one
     *
     * @param string                   $event   The event header, lower case
     * @param array<int|string, mixed> $payload The decoded payload
     *
     * @return array{0: string, 1: int, 2: int}|string
     */
    private function approval(string $event, array $payload): array|string
    {
        $attributes = $this->arr($payload, 'object_attributes');

        if ($event === 'merge request hook') {
            if (!in_array($this->str($attributes, 'action'), ['approved', 'approval'], true)) {
                return 'ignored: not an approval';
            }
            $mrIid = $this->int($attributes, 'iid');
        } elseif ($event === 'emoji hook') {
            $type = $this->str($payload, 'event_type') !== '' ? $this->str($payload, 'event_type') : $this->str($attributes, 'action');
            if ($this->str($attributes, 'name') !== 'thumbsup' || $this->str($attributes, 'awardable_type') !== 'MergeRequest' || $type === 'revoke') {
                return 'ignored: not a thumbs-up on the merge request';
            }
            $mrIid = $this->int($this->arr($payload, 'merge_request'), 'iid');
        } else {
            return 'ignored: not an approval event';
        }

        $approver  = $this->str($this->arr($payload, 'user'), 'username');
        $projectId = $this->int($this->arr($payload, 'project'), 'id');
        if ($approver === '' || $projectId === 0 || $mrIid === 0) {
            return 'ignored: the event names no user, project or merge request';
        }

        return [$approver, $projectId, $mrIid];
    }

    /**
     * The usernames whose approval resolves threads
     *
     * @return list<string>
     */
    private function owners(): array
    {
        $names = array_map('trim', explode(',', (string) getenv('KELPIE_APPROVAL_OWNERS')));

        return array_values(array_filter($names, static function (string $name): bool {
            return $name !== '';
        }));
    }

    /**
     * @param array<int|string, mixed> $data The data
     * @param string                   $key  The key
     *
     * @return array<int|string, mixed>
     */
    private function arr(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<int|string, mixed> $data The data
     * @param string                   $key  The key
     *
     * @return string
     */
    private function str(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<int|string, mixed> $data The data
     * @param string                   $key  The key
     *
     * @return int
     */
    private function int(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : 0;
    }
}
