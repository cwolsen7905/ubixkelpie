<?php

declare(strict_types=1);

namespace Kelpie\Repository\IssueTriageJob;

use DateTimeImmutable;

/**
 * Writes the issue triage queue
 */
interface IssueTriageJobWriterInterface
{
    /**
     * Queue one issue for triage; a second call for the same issue adds nothing
     *
     * @param string $projectPath The project path with namespace
     * @param int    $issueIid    The issue number in the project
     *
     * @return void
     */
    public function enqueue(string $projectPath, int $issueIid): void;

    /**
     * Queue one issue for a fix attempt
     *
     * A second call while the job is pending or running adds nothing. After the job has settled (done or
     * failed) it puts the same row back as pending with no attempts: a person removing and re-adding the gate
     * label is how a re-run is asked for. Locking and settling work on the id as for a triage job.
     *
     * @param string $projectPath The project path with namespace
     * @param int    $issueIid    The issue number in the project
     *
     * @return void
     */
    public function enqueueFix(string $projectPath, int $issueIid): void;

    /**
     * Try to lock a due job for a worker
     *
     * One conditional UPDATE on the attempt the job had when it was read, so when two workers race
     * only one changes the row. Which one did is read back with `getJobLockedBy()`.
     *
     * @param int               $id          The job id
     * @param int               $attempt     The attempt the job had when it was read
     * @param string            $workerId    The worker taking it
     * @param DateTimeImmutable $now         The current time
     * @param int               $lockSeconds How long the lock holds before another worker may take the job
     *
     * @return void
     */
    public function lockJob(int $id, int $attempt, string $workerId, DateTimeImmutable $now, int $lockSeconds): void;

    /**
     * Finish a job
     *
     * @param int     $id          The job id
     * @param ?string $triageClass The class posted, null for a skip
     *
     * @return void
     */
    public function markDone(int $id, ?string $triageClass): void;

    /**
     * Put a failed job back for a later attempt
     *
     * @param int               $id            The job id
     * @param DateTimeImmutable $nextAttemptAt Not due before this time
     * @param string            $error         A short fixed error, no issue text
     *
     * @return void
     */
    public function markRetry(int $id, DateTimeImmutable $nextAttemptAt, string $error): void;

    /**
     * Give up on a job
     *
     * @param int    $id    The job id
     * @param string $error A short fixed error, no issue text
     *
     * @return void
     */
    public function markFailed(int $id, string $error): void;
}
