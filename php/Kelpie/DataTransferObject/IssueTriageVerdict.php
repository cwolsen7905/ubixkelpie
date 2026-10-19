<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\IssueTriageClass;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The outcome of triaging one issue: a class and a short reason
 *
 * @see \Kelpie\Tests\DataTransferObject\IssueTriageVerdictTest PHPUnit test case
 */
final readonly class IssueTriageVerdict implements Dto
{
    /**
     * Constructor
     *
     * @param IssueTriageClass $class  The class the issue falls into
     * @param string           $reason A short reason, safe to show to a human
     */
    public function __construct(
        public IssueTriageClass $class,
        public string $reason,
    ) {
    }
}
