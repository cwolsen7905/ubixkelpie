<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

use Psr\Log\LoggerInterface as Logger;

/**
 * Turn a filtered event into an autofix job
 *
 * For now "enqueue" means log the job: there is no job store until step 3.
 *
 * @see \Kelpie\Tests\Autofix\EnqueueServiceTest PHPUnit test case
 */
final class EnqueueService
{
    /**
     * Constructor
     *
     * @param Logger      $logger Logger
     * @param EventFilter $filter The event filter
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly EventFilter $filter,
    ) {
    }

    /**
     * Filter an event and log the job
     *
     * @param string                   $event   The X-Gitlab-Event value
     * @param array<int|string, mixed> $payload The decoded payload
     *
     * @return string 'queued: ...' or 'skipped: reason'
     */
    public function handle(string $event, array $payload): string
    {
        $result = $this->filter->filter(strtolower($event), $payload);

        if (is_string($result)) {
            return 'skipped: ' . $result;
        }

        // One job per project, MR and head sha: the coalescing key.
        $key = $result->projectPath . '!' . $result->mrIid . '@' . $result->headSha;

        $this->logger->info('GitLab webhook: autofix job', [
            'after_autofix' => $result->afterAutofix,
            'discussion_id' => $result->discussionId,
            'head_sha'      => $result->headSha,
            'key'           => $key,
            'mr_iid'        => $result->mrIid,
            'project_path'  => $result->projectPath,
            'source'        => $result->source,
        ]);

        return 'queued: ' . $key;
    }
}
