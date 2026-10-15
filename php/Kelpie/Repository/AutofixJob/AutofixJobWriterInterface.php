<?php

declare(strict_types=1);

namespace Kelpie\Repository\AutofixJob;

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
}
