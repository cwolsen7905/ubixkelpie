<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\GateResult;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * Runs the gate of the project a fix is made in: an issue fix, or a push round on a merge request
 *
 * A project with its own gate command (the `gate_command` of its `projects` row, split on spaces, no shell) is gated by
 * that. Otherwise a `Makefile` with a `check` target is gated by `make check`, and any other checkout as Kelpie's own
 * (composer install, phpcs, phpstan, phpunit). The environment is exact and holds no GitLab token and no model key.
 *
 * The gate command `ci` runs nothing here: it is for a project whose toolchain Kelpie's image does not carry (Go, say),
 * whose merge request pipeline is the gate instead. The result passes and says so, so every reply can tell the person.
 *
 * @see \Kelpie\Tests\Service\Autofix\IssueGateServiceTest PHPUnit test case
 */
final class IssueGateService
{
    public const string PIPELINE_GATE = 'ci';

    private const int TIMEOUT = 1800;

    private const int MAX_OUTPUT = 6000;

    /**
     * Constructor
     *
     * @param Logger        $logger  Logger
     * @param ProcessRunner $runner  The process runner
     * @param GateRunner    $phpGate The gate of a PHP project
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $runner,
        private readonly GateRunner $phpGate,
    ) {
    }

    /**
     * Run the gate
     *
     * @param string  $directory The checkout
     * @param ?string $command   The project's own gate command, null for the default
     *
     * @return GateResult
     */
    public function run(string $directory, ?string $command = null): GateResult
    {
        if ($command !== null && trim($command) === self::PIPELINE_GATE) {
            return new GateResult(true, ran: 'not run here: this project\'s merge request pipeline is its gate.', local: false);
        }

        $argv = $command === null ? [] : (preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($argv === []) {
            $makefile = $directory . '/Makefile';
            if (!is_file($makefile) || preg_match('/^check\s*:/m', (string) file_get_contents($makefile)) !== 1) {
                return $this->phpGate->run($directory);
            }
            $argv = ['make', 'check'];
        }

        $step   = implode(' ', $argv);
        $env    = ['HOME' => dirname($directory), 'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')];
        $result = $this->runner->run($argv, $directory, $env, self::TIMEOUT);
        if ($result->exitCode === 0) {
            return new GateResult(true, ran: '`' . $step . '` passed.');
        }

        $this->logger->info('Issue fix gate failed', ['step' => $step]);

        return new GateResult(false, $step, substr($result->timedOut ? 'Timed out.' : trim($result->stdout . "\n" . $result->stderr), -self::MAX_OUTPUT));
    }
}
