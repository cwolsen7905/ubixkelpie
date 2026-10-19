<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\SqlRepository;

use DateTimeImmutable;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Criteria for finding issue triage jobs: the one a worker holds, or the next one that is due
 *
 * @see \Kelpie\Tests\DataTransferObject\SqlRepository\IssueTriageJobOptionsTest PHPUnit test case
 */
final readonly class IssueTriageJobOptions implements Dto
{
    /**
     * Constructor
     *
     * @param ?int               $id          Job id; with a worker id, the job that worker holds
     * @param ?string            $workerId    The worker whose lock the job must hold
     * @param string[]           $projects    For a due job, the project paths that may be taken
     * @param ?DateTimeImmutable $dueAt       For a due job, the time to be due at
     * @param int                $maxAttempts For a due job, the attempts at which it is no longer due
     */
    public function __construct(
        public ?int $id = null,
        public ?string $workerId = null,
        public array $projects = [],
        public ?DateTimeImmutable $dueAt = null,
        public int $maxAttempts = 3,
    ) {
    }
}
