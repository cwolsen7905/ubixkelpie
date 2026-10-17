<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixJob;
use Psr\Log\LoggerInterface as Logger;

/**
 * Turn a filtered event into an autofix job
 *
 * For now "enqueue" means log the job: there is no job store until step 3.
 *
 * @see \Kelpie\Tests\Service\Autofix\EnqueueServiceTest PHPUnit test case
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
        $result = $this->enqueue($event, $payload);

        if (is_string($result)) {
            return 'skipped: ' . $result;
        }

        return 'queued: ' . $this->key($result);
    }

    /**
     * Filter an event and log the job; the entry point of the webhook and of the sweep
     *
     * @param string                   $event   The X-Gitlab-Event value
     * @param array<int|string, mixed> $payload The decoded payload
     *
     * @return AutofixJob|string The job, or the reason it was skipped
     */
    public function enqueue(string $event, array $payload): AutofixJob|string
    {
        $result = $this->filter->filter(strtolower($event), $payload);

        if (is_string($result)) {
            return $result;
        }

        $this->logger->info('GitLab webhook: autofix job', [
            'after_autofix' => $result->afterAutofix,
            'discussion_id' => $result->discussionId,
            'head_sha'      => $result->headSha,
            'key'           => $this->key($result),
            'mr_iid'        => $result->mrIid,
            'project_path'  => $result->projectPath,
            'source'        => $result->source,
        ]);

        return $result;
    }

    /**
     * One job per project, MR and head sha: the coalescing key
     *
     * @param AutofixJob $job The job
     *
     * @return string
     */
    private function key(AutofixJob $job): string
    {
        return $job->projectPath . '!' . $job->mrIid . '@' . $job->headSha;
    }
}
