<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What a command's service tells it to print and to exit with
 *
 * @see \Kelpie\Tests\DataTransferObject\RunResultTest PHPUnit test case
 */
final readonly class RunResult implements Dto
{
    /**
     * Constructor
     *
     * @param bool     $success Whether the command exits with success
     * @param string[] $lines   The lines to print
     * @param bool     $isError Whether the lines are an error message
     */
    public function __construct(
        public bool $success,
        public array $lines = [],
        public bool $isError = false,
    ) {
    }
}
