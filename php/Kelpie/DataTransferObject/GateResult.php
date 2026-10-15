<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The outcome of the repo's gate on a checkout
 *
 * @see \Kelpie\Tests\DataTransferObject\GateResultTest PHPUnit test case
 */
final readonly class GateResult implements Dto
{
    /**
     * Constructor
     *
     * @param bool    $passed     Whether every step passed
     * @param ?string $failedStep The first failed step, null when it passed
     * @param string  $output     The failed step's output (tail), empty when it passed
     */
    public function __construct(
        public bool $passed,
        public ?string $failedStep = null,
        public string $output = '',
    ) {
    }
}
