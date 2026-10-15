<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The autofix caps (docs/design/mr-thread-autofix.md section 2), pure rules over counts
 *
 * @see \Kelpie\Tests\DataTransferObject\AutofixCapsTest PHPUnit test case
 */
final readonly class AutofixCaps implements Dto
{
    public const int DEFAULT_COMMITS_PER_MR      = 3;
    public const int DEFAULT_ATTEMPTS_PER_THREAD = 2;
    public const int DEFAULT_JOBS_PER_DAY        = 20;

    /**
     * Constructor
     *
     * @param int $commitsPerMr      Fix commits allowed per MR
     * @param int $attemptsPerThread Fix attempts allowed per thread
     * @param int $jobsPerDay        Jobs allowed per project in 24 hours
     */
    public function __construct(
        public int $commitsPerMr = self::DEFAULT_COMMITS_PER_MR,
        public int $attemptsPerThread = self::DEFAULT_ATTEMPTS_PER_THREAD,
        public int $jobsPerDay = self::DEFAULT_JOBS_PER_DAY,
    ) {
    }
}
