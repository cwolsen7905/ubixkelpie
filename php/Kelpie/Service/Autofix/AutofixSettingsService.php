<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Psr\Log\LoggerInterface as Logger;

/**
 * Reads the autofix settings from the process environment
 *
 * Nothing is validated: a service decides what a missing token or an unlisted project means for its own run.
 *
 * @see \Kelpie\Tests\Service\Autofix\AutofixSettingsServiceTest PHPUnit test case
 */
final class AutofixSettingsService
{
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
     * The settings of the current process environment
     *
     * @return AutofixSettings
     */
    public function fromEnvironment(): AutofixSettings
    {
        $this->logger->debug('Reading the autofix settings from the environment');

        $apiUrl   = rtrim((string) getenv('GITLAB_API_URL'), '/');
        $projects = $this->csv((string) (getenv('KELPIE_AUTOFIX_PROJECTS') ?: AutofixSettings::DEFAULT_PROJECT));

        return new AutofixSettings(
            apiUrl:        $apiUrl,
            botToken:      (string) getenv('KELPIE_BOT_GITLAB_TOKEN'),
            apiToken:      (string) getenv('GITLAB_API_TOKEN'),
            enabled:       getenv('KELPIE_AUTOFIX_ENABLED') === 'true',
            projects:      $projects,
            ciProjectId:   (string) getenv('CI_PROJECT_ID'),
            ciProjectPath: (string) getenv('CI_PROJECT_PATH'),
            claimTtl:      (int) (getenv('KELPIE_CLAIM_TTL_SECONDS') ?: AutofixSettings::DEFAULT_CLAIM_TTL),
            gitBaseUrl:    rtrim((string) (getenv('KELPIE_GIT_BASE_URL') ?: preg_replace('#/api/v\d+$#', '', $apiUrl)), '/'),
            fixerModel:    (string) (getenv('KELPIE_FIXER_MODEL') ?: AutofixSettings::DEFAULT_MODEL),
            openRouterKey: (string) getenv('OPENROUTER_API_KEY'),
            composerAuth:  (string) getenv('COMPOSER_AUTH'),
            sweepMaxRuns:  (int) (getenv('KELPIE_SWEEP_MAX_RUNS') ?: AutofixSettings::DEFAULT_SWEEP_RUNS),
            botUsername:   (string) (getenv('KELPIE_BOT_USERNAME') ?: AutofixSettings::DEFAULT_BOT),
            skipAuthors:   $this->csv((string) getenv('KELPIE_SKIP_AUTHORS')),
            skipBranches:  $this->csv((string) getenv('KELPIE_SKIP_BRANCHES')),
        );
    }

    /**
     * A comma-separated list as a clean list
     *
     * @param string $value The raw value
     *
     * @return string[]
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
