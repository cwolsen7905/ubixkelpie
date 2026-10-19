<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One claimed issue triage job
 *
 * @see \Kelpie\Tests\DataTransferObject\IssueTriageJobTest PHPUnit test case
 */
final readonly class IssueTriageJob implements Dto
{
    /**
     * Constructor
     *
     * @param int    $id          The job row id
     * @param string $projectPath The project path with namespace
     * @param int    $issueIid    The issue number in the project
     * @param int    $attempt     The attempt number this claim is, starting at 1
     */
    public function __construct(
        public int $id,
        public string $projectPath,
        public int $issueIid,
        public int $attempt,
    ) {
    }
}
