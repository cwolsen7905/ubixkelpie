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
     * @param int $commitsPerMr      Fix commits allowed per MR
     * @param int $attemptsPerThread Fix attempts allowed per thread
     * @param int $jobsPerMr         Jobs allowed per MR in 24 hours
     * @param int $jobsPerProject    Jobs allowed per project in 24 hours, across its MRs: the spend guard
     */
    public function __construct(
        public int $commitsPerMr = self::DEFAULT_COMMITS_PER_MR,
        public int $attemptsPerThread = self::DEFAULT_ATTEMPTS_PER_THREAD,
        public int $jobsPerMr = self::DEFAULT_JOBS_PER_MR,
        public int $jobsPerProject = self::DEFAULT_JOBS_PER_PROJECT,
    ) {
    }
}
