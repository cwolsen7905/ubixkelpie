<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;

/**
 * A process runner for tests: records every call and succeeds, except for programs told to fail
 */
final class ScriptedProcessRunner implements ProcessRunner
{
    /**
     * Every call: argv, cwd, env, timeout
     *
     * @var list<array{argv: string[], cwd: string, env: array<string, string>, timeout: int}>
     */
    private array $calls = [];

    /**
     * How many more times each program (argv[0]) fails
     *
     * @var array<string, int>
     */
    private array $failures = [];

    /**
     * Make the next calls of a program exit 2 with "<program> failed" on stdout
     *
     * @param string $program The program, argv[0]
     * @param int    $times   How many calls fail
     *
     * @return void
     */
    public function fail(string $program, int $times = 1): void
    {
        $this->failures[$program] = $times;
    }

    /**
     * The recorded calls
     *
     * @return list<array{argv: string[], cwd: string, env: array<string, string>, timeout: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * {@inheritDoc}
     */
    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult
    {
        $this->calls[] = ['argv' => $argv, 'cwd' => $cwd, 'env' => $env, 'timeout' => $timeoutSeconds];

        $program = $argv[0] ?? '';
        if (($this->failures[$program] ?? 0) > 0) {
            $this->failures[$program]--;

            return new ProcessResult(2, $program . ' failed: line 1', '');
        }

        return new ProcessResult(0, '', '');
    }
}
