<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\GateResult;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * Runs the repo's own gate in a checkout: composer install here, then phpcs, phpstan, phpunit through the gate executor
 *
 * The same three tools CI runs. `composer install` stays here because it needs `COMPOSER_AUTH` (the registry
 * credential), which only it is given; it runs without scripts and plugins, so it runs none of the repository's code.
 * Everything after it does run the repository's code, so it goes to the executor: the gate pod when one is configured
 * (docs/security/gate-pod.md). `runSteps()` is the same for any other gate (a project's `make check`).
 *
 * @see \Kelpie\Tests\Sandbox\GateRunnerTest PHPUnit test case
 */
final class GateRunner
{
    private const int STEP_TIMEOUT = 900;

    private const array COMPOSER_INSTALL = ['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins', '--prefer-dist'];

    /**
     * The steps that run the repository's code, in order
     */
    private const array STEPS = [
        'phpcs'   => ['vendor/bin/phpcs'],
        'phpstan' => ['vendor/bin/phpstan', 'analyse', '--no-progress', '--no-interaction'],
        'phpunit' => ['vendor/bin/phpunit'],
    ];

    /**
     * Constructor
     *
     * @param Logger        $logger       Logger
     * @param ProcessRunner $runner       The process runner (composer install)
     * @param GateExecutor  $executor     Where the steps that run the repository's code run
     * @param string        $composerAuth The COMPOSER_AUTH JSON, '' for none
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $runner,
        private readonly GateExecutor $executor,
        private readonly string $composerAuth = '',
    ) {
    }

    /**
     * Run the PHP gate
     *
     * @param string $directory The checkout
     *
     * @return GateResult Stops at the first red step
     */
    public function run(string $directory): GateResult
    {
        $env = [
            'HOME' => dirname($directory),
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];
        // The registry credential is for composer alone: the steps after it run the repository's own code.
        $result = $this->runner->run(self::COMPOSER_INSTALL, $directory, $this->composerAuth === '' ? $env : $env + ['COMPOSER_AUTH' => $this->composerAuth], self::STEP_TIMEOUT);
        if ($result->exitCode !== 0) {
            $this->logger->info('Autofix gate failed', ['step' => 'composer install']);

            return new GateResult(false, 'composer install', substr($result->timedOut ? 'Timed out.' : trim($result->stdout . "\n" . $result->stderr), -GateExecutor::MAX_OUTPUT));
        }

        return $this->runSteps($directory, self::STEPS, self::STEP_TIMEOUT, 'phpcs, phpstan and phpunit passed.');
    }

    /**
     * Run named steps through the executor
     *
     * @param string                      $directory      The checkout
     * @param array<string, list<string>> $steps          Each step's name (for the person) and argv, in order
     * @param int                         $timeoutSeconds Each step's limit
     * @param string                      $ran            What to say when every step passed
     *
     * @return GateResult The first red step, or "gate" when the gate could not be run at all
     */
    public function runSteps(string $directory, array $steps, int $timeoutSeconds, string $ran): GateResult
    {
        $names = array_keys($steps);
        $run   = $this->executor->run($directory, array_values($steps), $timeoutSeconds);
        if ($run->unavailable !== '') {
            $this->logger->warning('Autofix gate could not run', ['reason' => $run->unavailable]);

            return new GateResult(false, 'gate', $run->unavailable);
        }

        foreach ($run->results as $index => $result) {
            if ($result->exitCode !== 0 || $result->timedOut) {
                $this->logger->info('Autofix gate failed', ['step' => $names[$index] ?? 'gate']);

                return new GateResult(false, (string) ($names[$index] ?? 'gate'), $result->output);
            }
        }

        // Every result passed: only a pass when every step has one.
        return count($run->results) === count($steps) ? new GateResult(true, ran: $ran) : new GateResult(false, 'gate', 'gate unavailable: not every step ran');
    }
}
