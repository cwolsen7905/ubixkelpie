<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\GateRun;
use Kelpie\DataTransferObject\GateStepResult;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * Runs the gate's steps here, through the process runner, with an exact environment (HOME and PATH, nothing else)
 *
 * In the worker this is the path used while `KELPIE_GATE_URL` is unset; the gate pod's server runs its steps with it too.
 *
 * @see \Kelpie\Tests\Sandbox\LocalGateExecutorTest PHPUnit test case
 */
final class LocalGateExecutor implements GateExecutor
{
    /**
     * Constructor
     *
     * @param Logger        $logger Logger
     * @param ProcessRunner $runner The process runner
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, string[]> $steps Each step's program and arguments, in order
     */
    public function run(string $directory, array $steps, int $timeoutSeconds): GateRun
    {
        $env = [
            'HOME' => dirname($directory),
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];

        $results = [];
        foreach ($steps as $argv) {
            $result    = $this->runner->run($argv, $directory, $env, $timeoutSeconds);
            $output    = $result->timedOut ? 'Timed out.' : trim($result->stdout . "\n" . $result->stderr);
            $results[] = new GateStepResult($result->exitCode, $result->timedOut, substr($output, -self::MAX_OUTPUT));
            if ($result->exitCode !== 0 || $result->timedOut) {
                $this->logger->debug('Gate step failed', ['program' => basename($argv[0] ?? '')]);
                break;
            }
        }

        return new GateRun($results);
    }
}
