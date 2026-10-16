<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

use Kelpie\Autofix\DataTransferObject\AutofixJob;
use Psr\Log\LoggerInterface as Logger;

/**
 * Decide whether a Note Hook or Pipeline Hook payload becomes an autofix job
 *
 * No I/O beyond logging a skip. Returns the job, or a short reason string when the event is skipped.
 * Loop guards (design section 3 and 6): bot and Shepherd authors, Kelpie's own
 * markers, the Kelpie-Autofix trailer, Shepherd-owned branches.
 *
 * @see \Kelpie\Tests\Autofix\EventFilterTest PHPUnit test case
 */
final class EventFilter
{
    /**
     * Pipeline stages whose failure is fixable (design decision 7: gate failures, not deploy or infra)
     */
    public const array GATE_STAGES = ['lint', 'test', 'lint-and-test', 'static-analysis'];

    private const string MARKER = '<!-- kelpie:';

    private const string TRAILER = 'Kelpie-Autofix:';

    /**
     * Constructor
     *
     * @param Logger   $logger         Logger
     * @param string   $botUsername    The Kelpie bot user, whose events are always ignored
     * @param string[] $skipAuthors    Other usernames to ignore (Shepherd agents)
     * @param string[] $projects       Allow-listed project paths
     * @param string[] $targetBranches MR target branches that may be fixed
     * @param string[] $skipBranches   Source branch prefixes owned by Shepherd
     * @param string[] $gateStages     Pipeline stages whose failure is fixable
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $botUsername = 'kelpie-bot',
        private readonly array $skipAuthors = [],
        private readonly array $projects = ['ubixsys/ubixkelpie'],
        private readonly array $targetBranches = ['dev', 'staging'],
        private readonly array $skipBranches = [],
        private readonly array $gateStages = self::GATE_STAGES,
    ) {
    }

    /**
     * Filter one event
     *
     * @param string                   $event   The X-Gitlab-Event value, lower-cased
     * @param array<int|string, mixed> $payload The decoded payload
     *
     * @return AutofixJob|string The job, or the reason it was skipped
     */
    public function filter(string $event, array $payload): AutofixJob|string
    {
        $result = match ($event) {
            'note hook' => $this->note($payload),
            'pipeline hook' => $this->pipeline($payload),
            default => 'not an autofix event',
        };

        if (is_string($result)) {
            $this->logger->info('GitLab webhook: autofix skipped', ['event' => $event, 'reason' => $result]);
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $payload The Note Hook payload
     *
     * @return AutofixJob|string
     */
    private function note(array $payload): AutofixJob|string
    {
        $oa = $this->arr($payload, 'object_attributes');
        $mr = $this->arr($payload, 'merge_request');

        if (($oa['noteable_type'] ?? '') !== 'MergeRequest') {
            return 'not an MR note';
        }
        if (($oa['system'] ?? false) === true) {
            return 'system note';
        }
        if (!in_array($oa['type'] ?? null, ['DiffNote', 'DiscussionNote'], true)) {
            return 'not in a thread';
        }
        $note = $this->str($oa, 'note');
        if (str_contains($note, self::MARKER)) {
            return 'kelpie marker';
        }
        $skip = $this->skipUser($payload) ?? $this->skipMr($payload, $mr);
        if ($skip !== null) {
            return $skip;
        }

        $commit = $this->arr($mr, 'last_commit');
        $sha    = $this->str($commit, 'id');
        if ($sha === '') {
            return 'no head sha';
        }

        return new AutofixJob(
            $this->str($this->arr($payload, 'project'), 'path_with_namespace'),
            $this->int($mr, 'iid'),
            $sha,
            'note',
            $this->str($oa, 'discussion_id') ?: null,
            str_contains($this->str($commit, 'message'), self::TRAILER),
        );
    }

    /**
     * @param array<int|string, mixed> $payload The Pipeline Hook payload
     *
     * @return AutofixJob|string
     */
    private function pipeline(array $payload): AutofixJob|string
    {
        $oa = $this->arr($payload, 'object_attributes');
        $mr = $this->arr($payload, 'merge_request');

        if ($mr === [] || $this->str($oa, 'source') !== 'merge_request_event') {
            return 'not an MR pipeline';
        }
        if ($this->str($oa, 'status') !== 'failed') {
            return 'pipeline not failed';
        }
        $skip = $this->skipUser($payload) ?? $this->skipMr($payload, $mr);
        if ($skip !== null) {
            return $skip;
        }
        if (str_contains($this->str($this->arr($payload, 'commit'), 'message'), self::TRAILER)) {
            return 'head is a kelpie-autofix commit';
        }
        if (!$this->hasFailedGateJob($payload)) {
            return 'no failed gate job';
        }

        $sha = $this->str($oa, 'sha');
        if ($sha === '') {
            return 'no head sha';
        }

        return new AutofixJob(
            $this->str($this->arr($payload, 'project'), 'path_with_namespace'),
            $this->int($mr, 'iid'),
            $sha,
            'pipeline',
        );
    }

    /**
     * Whether a job that is not allowed to fail failed in a gate stage
     *
     * @param array<int|string, mixed> $payload The Pipeline Hook payload
     *
     * @return bool
     */
    private function hasFailedGateJob(array $payload): bool
    {
        $builds = $payload['builds'] ?? [];
        foreach (is_array($builds) ? $builds : [] as $build) {
            if (!is_array($build) || ($build['status'] ?? '') !== 'failed' || ($build['allow_failure'] ?? false) === true) {
                continue;
            }
            if (in_array($this->str($build, 'stage'), $this->gateStages, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $payload The payload
     *
     * @return ?string The skip reason when the acting user is the bot or a skipped agent
     */
    private function skipUser(array $payload): ?string
    {
        $username = $this->str($this->arr($payload, 'user'), 'username');

        if ($username !== '' && ($username === $this->botUsername || in_array($username, $this->skipAuthors, true))) {
            return 'author is the bot or a Shepherd agent';
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $payload The payload
     * @param array<int|string, mixed> $mr      The merge_request object
     *
     * @return ?string The skip reason for the project or MR, null when it may be fixed
     */
    private function skipMr(array $payload, array $mr): ?string
    {
        $path = $this->str($this->arr($payload, 'project'), 'path_with_namespace');

        if (!in_array($path, $this->projects, true)) {
            return 'project not allow-listed';
        }
        if ($this->int($mr, 'iid') === 0) {
            return 'no MR iid';
        }
        if (($mr['state'] ?? 'opened') !== 'opened') {
            return 'MR not open';
        }
        $title = $this->str($mr, 'title');
        if (($mr['draft'] ?? $mr['work_in_progress'] ?? false) === true || preg_match('/^\s*\[?draft\b/i', $title) === 1) {
            return 'MR is a draft';
        }
        if (!in_array($this->str($mr, 'target_branch'), $this->targetBranches, true)) {
            return 'target branch not fixable';
        }
        $source = $this->str($mr, 'source_branch');
        foreach ($this->skipBranches as $prefix) {
            if ($prefix !== '' && str_starts_with($source, $prefix)) {
                return 'Shepherd-owned branch';
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $data The array
     * @param string                   $key  The key
     *
     * @return array<int|string, mixed>
     */
    private function arr(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<int|string, mixed> $data The array
     * @param string                   $key  The key
     *
     * @return string
     */
    private function str(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<int|string, mixed> $data The array
     * @param string                   $key  The key
     *
     * @return int
     */
    private function int(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : 0;
    }
}
