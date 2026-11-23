<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The autofix caps (attempts per thread, fix commits per MR, jobs per MR and per project in 24 hours), pure rules over counts
 *
 * @see \Kelpie\Tests\DataTransferObject\AutofixCapsTest PHPUnit test case
 */
final readonly class AutofixCaps implements Dto
{
    public const int DEFAULT_COMMITS_PER_MR      = 3;
    public const int DEFAULT_ATTEMPTS_PER_THREAD = 2;
    public const int DEFAULT_JOBS_PER_MR         = 30;
    public const int DEFAULT_JOBS_PER_PROJECT    = 200;

    /**
     * Constructor
     *
     * The soft limits are the current values; a hard limit of null is unlimited, so a cap that is not given a hard
     * limit only warns (at its soft value) and never stops the work.
     *
     * @param int  $commitsPerMr          Fix commits allowed per MR (soft)
     * @param int  $attemptsPerThread     Fix attempts allowed per thread (soft)
     * @param int  $jobsPerMr             Jobs allowed per MR in 24 hours (soft)
     * @param int  $jobsPerProject        Jobs allowed per project in 24 hours, across its MRs: the spend guard (soft)
     * @param ?int $commitsPerMrHard      Fix commits per MR that stop the work; null is unlimited
     * @param ?int $attemptsPerThreadHard Fix attempts per thread that stop the work; null is unlimited
     * @param ?int $jobsPerMrHard         Jobs per MR in 24 hours that stop the work; null is unlimited
     * @param ?int $jobsPerProjectHard    Jobs per project in 24 hours that stop the work; null is unlimited
     */
    public function __construct(
        public int $commitsPerMr = self::DEFAULT_COMMITS_PER_MR,
        public int $attemptsPerThread = self::DEFAULT_ATTEMPTS_PER_THREAD,
        public int $jobsPerMr = self::DEFAULT_JOBS_PER_MR,
        public int $jobsPerProject = self::DEFAULT_JOBS_PER_PROJECT,
        public ?int $commitsPerMrHard = null,
        public ?int $attemptsPerThreadHard = null,
        public ?int $jobsPerMrHard = null,
        public ?int $jobsPerProjectHard = null,
    ) {
    }
}
