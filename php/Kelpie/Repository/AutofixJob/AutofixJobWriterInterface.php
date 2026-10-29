<?php

declare(strict_types=1);

namespace Kelpie\Repository\AutofixJob;

use DateTimeImmutable;
use Kelpie\Model\AutofixJobRecord;

/**
 * Writes autofix jobs
 */
interface AutofixJobWriterInterface
{
    /**
     * Persist a new pending job (the new id is set on the model)
     *
     * @param AutofixJobRecord $job The job to create
     *
     * @return void
     */
    public function createJob(AutofixJobRecord $job): void;

    /**
     * Supersede a pending job with a newer one (a job that is no longer pending is left alone)
     *
     * @param int $id The job id
     *
     * @return void
     */
    public function supersedeJob(int $id): void;

    /**
     * Mark a pending job as running and count the attempt (a job that is no longer pending is left alone)
     *
     * @param int $id The job id
     *
     * @return void
     */
    public function markRunning(int $id): void;

    /**
     * Finish a running job
     *
     * @param int     $id          The job id
     * @param string  $status      The final status, done or failed
     * @param ?string $triageClass The triage class, when known
     * @param ?string $commitSha   The fix commit, when one was pushed
     *
     * @return void
     */
    public function markFinished(int $id, string $status, ?string $triageClass = null, ?string $commitSha = null): void;

    /**
     * Take the lease on a job: conditional, so of several workers only one wins
     *
     * @param int               $id          The job id
     * @param int               $attempt     The attempt count the worker read
     * @param string            $workerId    The worker
     * @param DateTimeImmutable $now         The current time
     * @param int               $lockSeconds How long the lease lasts
     *
     * @return void
     */
    public function lockJob(int $id, int $attempt, string $workerId, DateTimeImmutable $now, int $lockSeconds): void;

    /**
     * Finish a job the worker holds with no more to do
     *
     * @param int $id The job id
     *
     * @return void
     */
    public function markDone(int $id): void;

    /**
     * Put a job back for another attempt
     *
     * @param int               $id            The job id
     * @param DateTimeImmutable $nextAttemptAt When it is next due
     * @param string            $error         Why this attempt failed
     *
     * @return void
     */
    public function markRetry(int $id, DateTimeImmutable $nextAttemptAt, string $error): void;

    /**
     * Put a job back until a later time without counting the attempt that was held
     *
     * @param int               $id    The job id
     * @param DateTimeImmutable $until When it is next due
     * @param string            $why   Why it waits
     *
     * @return void
     */
    public function markDeferred(int $id, DateTimeImmutable $until, string $why): void;

    /**
     * Give up on a job
     *
     * @param int    $id    The job id
     * @param string $error Why the last attempt failed
     *
     * @return void
     */
    public function markFailed(int $id, string $error): void;
}
