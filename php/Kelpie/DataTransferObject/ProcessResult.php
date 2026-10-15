<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The result of one sandboxed process
 *
 * @see \Kelpie\Tests\DataTransferObject\ProcessResultTest PHPUnit test case
 */
final readonly class ProcessResult implements Dto
{
    /**
     * Constructor
     *
     * @param int    $exitCode Exit code; -1 when it could not start or was killed for running too long, so 0 alone is success
     * @param string $stdout   Standard output
     * @param string $stderr   Standard error
     * @param bool   $timedOut Whether it was killed for running too long
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut = false,
    ) {
    }
}
