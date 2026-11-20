<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixCaps;
use Psr\Log\LoggerInterface as Logger;

/**
 * Reads the autofix caps from the process environment
 *
 * `KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY`, `KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY`, `KELPIE_AUTOFIX_COMMITS_PER_MR` and
 * `KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD`, each a
 * whole number of at least 1. A variable that is not set (or empty) gives the default; one that is not a valid
 * number gives the default and a logged warning, so a typo in Vault never switches a cap off.
 *
 * @see \Kelpie\Tests\Service\Autofix\AutofixCapsServiceTest PHPUnit test case
 */
final class AutofixCapsService
{
    private const int MAX_DIGITS = 9;

    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * The caps of the current process environment
     *
     * @return AutofixCaps
     */
    public function fromEnvironment(): AutofixCaps
    {
        return new AutofixCaps(
            commitsPerMr:      $this->read('KELPIE_AUTOFIX_COMMITS_PER_MR', AutofixCaps::DEFAULT_COMMITS_PER_MR),
            attemptsPerThread: $this->read('KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD', AutofixCaps::DEFAULT_ATTEMPTS_PER_THREAD),
            jobsPerMr:         $this->read('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY', AutofixCaps::DEFAULT_JOBS_PER_MR),
            jobsPerProject:    $this->read('KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY', AutofixCaps::DEFAULT_JOBS_PER_PROJECT),
        );
    }

    /**
     * One cap from one variable
     *
     * @param string $name    The variable
     * @param int    $default The default
     *
     * @return int
     */
    private function read(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }

        $value = trim($raw);
        if (!ctype_digit($value) || strlen($value) > self::MAX_DIGITS || (int) $value < 1) {
            $this->logger->warning('Invalid autofix cap setting, using the default', ['default' => $default, 'name' => $name, 'value' => $raw]);

            return $default;
        }

        return (int) $value;
    }
}
