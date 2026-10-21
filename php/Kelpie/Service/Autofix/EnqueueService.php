<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Policy\EventFilter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Turn a filtered event into an autofix job
 *
 * The webhook's `handle` writes the job to the job table (coalesced and capped), for `kelpie:work` to run; it
 * writes nothing while autofix is off. `enqueue` only filters and logs, and is also the sweep's entry point.
 *
 * @see \Kelpie\Tests\Service\Autofix\EnqueueServiceTest PHPUnit test case
 */
final class EnqueueService
{
    /**
     * Constructor
     *
     * @param Logger            $logger  Logger
     * @param EventFilter       $filter  The event filter
     * @param AutofixJobService $jobs    The job table
     * @param bool              $enabled Whether `KELPIE_AUTOFIX_ENABLED` is on
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly EventFilter $filter,
        private readonly AutofixJobService $jobs,
        private readonly bool $enabled = false,
    ) {
    }

    /**
     * Filter an event and write the job
     *
     * @param string                   $event   The X-Gitlab-Event value
     * @param array<int|string, mixed> $payload The decoded payload
     *
     * @return string 'queued: key (outcome)' or 'skipped: reason'
     */
    public function handle(string $event, array $payload): string
    {
        $result = $this->enqueue($event, $payload);

        if (is_string($result)) {
            return 'skipped: ' . $result;
        }
        if (!$this->enabled) {
            return 'skipped: autofix disabled';
        }

        [$outcome] = $this->jobs->pendingJob($result, new DateTimeImmutable());

        return 'queued: ' . $this->key($result) . ' (' . $outcome->value . ')';
    }

    /**
     * Filter an event and log the job, writing nothing; the entry point of the webhook and of the sweep
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
        return $job->projectPath . '!' . $job->mrIid . '@' . $job->headSha . ($job->discussionId === null ? '' : '#' . $job->discussionId);
    }
}
