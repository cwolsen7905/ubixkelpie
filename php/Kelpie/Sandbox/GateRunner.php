<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\GateResult;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * Runs the repo's own gate in a checkout: composer install, then phpcs, phpstan, phpunit
 *
 * The same three tools CI runs. The environment is exact and holds no GitLab token. `COMPOSER_AUTH`
 * (the registry credential) is passed through when set, because install needs it.
 *
 * @see \Kelpie\Tests\Sandbox\GateRunnerTest PHPUnit test case
 */
final class GateRunner
{
    private const int STEP_TIMEOUT = 900;

    private const int MAX_OUTPUT = 6000;

    /**
     * The steps, in order
     */
    private const array STEPS = [
        'composer install' => ['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'],
        'phpcs'            => ['vendor/bin/phpcs'],
        'phpstan'          => ['vendor/bin/phpstan', 'analyse', '--no-progress', '--no-interaction'],
        'phpunit'          => ['vendor/bin/phpunit'],
    ];

    /**
     * Constructor
     *
     * @param Logger        $logger       Logger
     * @param ProcessRunner $runner       The process runner
     * @param string        $composerAuth The COMPOSER_AUTH JSON, '' for none
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $runner,
        private readonly string $composerAuth = '',
    ) {
    }

    /**
     * Run the gate
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
        if ($this->composerAuth !== '') {
            $env['COMPOSER_AUTH'] = $this->composerAuth;
        }

        foreach (self::STEPS as $name => $argv) {
            $result = $this->runner->run($argv, $directory, $env, self::STEP_TIMEOUT);

            if ($result->exitCode !== 0) {
                $output = $result->timedOut ? 'Timed out.' : trim($result->stdout . "\n" . $result->stderr);

                $this->logger->info('Autofix gate failed', ['step' => $name]);

                return new GateResult(false, $name, substr($output, -self::MAX_OUTPUT));
            }
        }

        return new GateResult(true);
    }
}
