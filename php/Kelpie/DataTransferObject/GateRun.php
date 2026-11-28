<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What a gate executor came back with: one result per step that ran, in order, stopping at the first that failed
 *
 * When `$unavailable` is not empty the gate could not be run at all (or its answer could not be trusted): that is a
 * failure, never a pass, whatever `$results` holds.
 *
 * @see \Kelpie\Tests\DataTransferObject\GateRunTest PHPUnit test case
 */
final readonly class GateRun implements Dto
{
    /**
     * Constructor
     *
     * @param GateStepResult[] $results     One per step that ran, in order
     * @param string           $unavailable Why the gate could not be run ("gate unavailable: ..."), empty when it ran
     */
    public function __construct(
        public array $results,
        public string $unavailable = '',
    ) {
    }
}
