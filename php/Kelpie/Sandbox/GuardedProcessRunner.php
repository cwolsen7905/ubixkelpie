<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Security\ActionGuard;
use Psr\Log\LoggerInterface as Logger;

/**
 * A process runner that runs only what an unattended run may do, and refuses the rest without starting it
 *
 * A refusal is a result with exit code 126 and the reason on stderr, so callers treat it as any failed command.
 *
 * @see \Kelpie\Tests\Sandbox\GuardedProcessRunnerTest PHPUnit test case
 */
final class GuardedProcessRunner implements ProcessRunner
{
    public const int REFUSED = 126;

    /**
     * Constructor
     *
     * @param Logger        $logger Logger
     * @param ProcessRunner $inner  The runner that does the work
     * @param ActionGuard   $guard  The action list
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $inner,
        private readonly ActionGuard $guard,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string[]              $argv The program and its arguments (no shell)
     * @param array<string, string> $env  The whole environment of the process
     */
    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult
    {
        $argv   = array_values($argv);
        $reason = $this->guard->refusal($argv);
        if ($reason !== null) {
            $this->logger->warning('Unattended run refused a command', ['program' => basename($argv[0] ?? ''), 'reason' => $reason]);

            return new ProcessResult(self::REFUSED, '', 'Refused: ' . $reason);
        }

        return $this->inner->run($argv, $cwd, $env, $timeoutSeconds);
    }
}
