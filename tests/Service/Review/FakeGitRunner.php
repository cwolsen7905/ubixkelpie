<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;

/**
 * A git for the tests: answers from a table keyed by a fragment of the command line, and fails for anything else
 */
final class FakeGitRunner implements ProcessRunner
{
    /**
     * Every call: the command line as one string, the working directory, the environment and the timeout
     *
     * @var list<array{line: string, cwd: string, env: array<string, string>, timeout: int}>
     */
    private array $calls = [];

    /**
     * @var array<string, string>
     */
    private array $answers = [];

    /**
     * The command lines run so far
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_column($this->calls, 'line');
    }

    /**
     * Answer commands containing a fragment with this standard output
     *
     * @param string $fragment A fragment of the command line
     * @param string $stdout   What git prints
     *
     * @return self
     */
    public function answer(string $fragment, string $stdout): self
    {
        $this->answers[$fragment] = $stdout;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult
    {
        $line          = implode(' ', $argv);
        $this->calls[] = ['cwd' => $cwd, 'env' => $env, 'line' => $line, 'timeout' => $timeoutSeconds];

        foreach ($this->answers as $fragment => $stdout) {
            if (str_contains($line, $fragment)) {
                return new ProcessResult(0, $stdout, '');
            }
        }

        return new ProcessResult(128, '', 'fatal');
    }
}
