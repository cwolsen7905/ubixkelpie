<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\ProcessResult;

/**
 * Runs one process with an exact environment and a time limit
 */
interface ProcessRunnerInterface
{
    /**
     * Run a command
     *
     * @param string[]              $argv           The program and its arguments (no shell)
     * @param string                $cwd            The working directory
     * @param array<string, string> $env            The whole environment of the process; nothing is inherited
     * @param int                   $timeoutSeconds Wall-clock limit
     *
     * @return ProcessResult
     */
    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult;
}
