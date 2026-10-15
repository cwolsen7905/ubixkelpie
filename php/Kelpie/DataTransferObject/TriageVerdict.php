<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\TriageClass;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The outcome of triaging one thread: a class and a short reason
 *
 * @see \Kelpie\Tests\DataTransferObject\TriageVerdictTest PHPUnit test case
 */
final readonly class TriageVerdict implements Dto
{
    /**
     * Constructor
     *
     * @param TriageClass $class  The class the thread falls into
     * @param string      $reason A short reason, safe to show to a human
     */
    public function __construct(
        public TriageClass $class,
        public string $reason,
    ) {
    }
}
