<?php

declare(strict_types=1);

namespace Kelpie\Repository\AutofixJob;

use DateTimeImmutable;
use Kelpie\Model\AutofixJobRecord;

/**
 * Reads autofix jobs
 */
interface AutofixJobReaderInterface
{
    /**
     * Get a job by id
     *
     * @param int $id The job id
     *
     * @return ?AutofixJobRecord The job, or null when unknown
     */
    public function getJobById(int $id): ?AutofixJobRecord;

    /**
     * Get the open (pending or running) job for an MR and thread
     *
     * @param string $projectPath Project path with namespace
     * @param int    $mrIid       Merge request IID
     * @param string $threadKey   The discussion id, or '' for an MR-level job
     *
     * @return ?AutofixJobRecord The open job, or null when there is none
     */
    public function getOpenJob(string $projectPath, int $mrIid, string $threadKey): ?AutofixJobRecord;

    /**
     * Count the fix commits already pushed for an MR
     *
     * @param string $projectPath Project path with namespace
     * @param int    $mrIid       Merge request IID
     *
     * @return int The number of jobs that recorded a commit
     */
    public function countFixCommitsForMr(string $projectPath, int $mrIid): int;

    /**
     * Count the fix attempts already made on a thread
     *
     * @param string $projectPath Project path with namespace
     * @param int    $mrIid       Merge request IID
     * @param string $threadKey   The discussion id, or '' for an MR-level job
     *
     * @return int The number of finished jobs (done or failed) for the thread
     */
    public function countAttemptsForThread(string $projectPath, int $mrIid, string $threadKey): int;

    /**
     * Count the jobs a project has created since a moment
     *
     * @param string            $projectPath Project path with namespace
     * @param DateTimeImmutable $since       The start of the window
     *
     * @return int The number of jobs created since then, superseded ones included
     */
    public function countJobsSince(string $projectPath, DateTimeImmutable $since): int;

    /**
     * The oldest MR job a worker may take: pending or with an expired lease, due, and under the attempt limit
     *
     * @param string[]          $projects    Allow-listed project paths
     * @param DateTimeImmutable $now         The current time
     * @param int               $maxAttempts Attempts after which a job is not offered again
     *
     * @return ?AutofixJobRecord
     */
    public function getDueJob(array $projects, DateTimeImmutable $now, int $maxAttempts): ?AutofixJobRecord;

    /**
     * The running job this worker holds the lease on
     *
     * @param int    $id       The job id
     * @param string $workerId The worker
     *
     * @return ?AutofixJobRecord
     */
    public function getJobLockedBy(int $id, string $workerId): ?AutofixJobRecord;
}
