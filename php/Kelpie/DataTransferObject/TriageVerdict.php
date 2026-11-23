<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageConfidence;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The outcome of triaging one thread: a class, a short reason and how sure the model was
 *
 * @see \Kelpie\Tests\DataTransferObject\TriageVerdictTest PHPUnit test case
 */
final readonly class TriageVerdict implements Dto
{
    /**
     * Constructor
     *
     * @param TriageClass      $class      The class the thread falls into
     * @param string           $reason     A short reason, safe to show to a human
     * @param ?Completion      $completion The model call behind it, null when none was made
     * @param TriageConfidence $confidence How sure the model was; High for a verdict no model made (a refusal, a fixed answer)
     */
    public function __construct(
        public TriageClass $class,
        public string $reason,
        public ?Completion $completion = null,
        public TriageConfidence $confidence = TriageConfidence::High,
    ) {
    }
}
