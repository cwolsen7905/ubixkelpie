<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\TriageConfidence;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The outcome of triaging one issue: a class, a short reason and how sure the model was
 *
 * @see \Kelpie\Tests\DataTransferObject\IssueTriageVerdictTest PHPUnit test case
 */
final readonly class IssueTriageVerdict implements Dto
{
    /**
     * Constructor
     *
     * @param IssueTriageClass $class      The class the issue falls into
     * @param string           $reason     A short reason, safe to show to a human
     * @param ?Completion      $completion The model call behind it
     * @param TriageConfidence $confidence How sure the model was; Low when omitted so policy fails closed
     */
    public function __construct(
        public IssueTriageClass $class,
        public string $reason,
        public ?Completion $completion = null,
        public TriageConfidence $confidence = TriageConfidence::Low,
    ) {
    }
}
