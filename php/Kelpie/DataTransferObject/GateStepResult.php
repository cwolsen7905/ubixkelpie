<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The outcome of one gate step: a step passed when it exited 0 and was not killed for running too long
 *
 * @see \Kelpie\Tests\DataTransferObject\GateStepResultTest PHPUnit test case
 */
final readonly class GateStepResult implements Dto
{
    /**
     * Constructor
     *
     * @param int    $exitCode Exit code; -1 when it could not start or was killed
     * @param bool   $timedOut Whether it was killed for running too long
     * @param string $output   The tail of its output ("Timed out." when it was killed), empty when it printed nothing
     */
    public function __construct(
        public int $exitCode,
        public bool $timedOut,
        public string $output,
    ) {
    }
}
