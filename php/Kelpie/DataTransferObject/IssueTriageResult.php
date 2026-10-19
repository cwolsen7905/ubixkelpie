<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\IssueTriageOutcome;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What one triage run did with an issue
 *
 * @see \Kelpie\Tests\DataTransferObject\IssueTriageResultTest PHPUnit test case
 */
final readonly class IssueTriageResult implements Dto
{
    /**
     * Constructor
     *
     * @param IssueTriageOutcome $outcome How the run ended
     * @param ?IssueTriageClass  $class   The class posted, set when triaged
     * @param string             $detail  A short fixed reason for a skip or a failure
     */
    public function __construct(
        public IssueTriageOutcome $outcome,
        public ?IssueTriageClass $class = null,
        public string $detail = '',
    ) {
    }
}
