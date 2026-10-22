<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The environment the autofix commands run on: GitLab, the allow-list, the caps' neighbours and the fixer's model
 *
 * Read once, from the process environment, by `AutofixSettingsService` when the CLI container is built. Nothing here is validated:
 * a service decides what a missing token or an unlisted project means for its own run.
 *
 * @see \Kelpie\Tests\DataTransferObject\AutofixSettingsTest PHPUnit test case
 */
final readonly class AutofixSettings implements Dto
{
    public const string DEFAULT_PROJECT = 'ubixsys/ubixkelpie';
    public const string DEFAULT_MODEL   = 'anthropic/claude-sonnet-5.5';
    public const string DEFAULT_BOT     = 'kelpie-bot';
    public const int DEFAULT_CLAIM_TTL  = 1800;
    public const int DEFAULT_SWEEP_RUNS = 5;

    /**
     * Constructor
     *
     * @param string   $apiUrl        The GitLab API URL, no trailing slash
     * @param string   $botToken      The kelpie-bot token (writes)
     * @param string   $apiToken      The read token of the dry-run triage
     * @param bool     $enabled       Whether autofix may run (KELPIE_AUTOFIX_ENABLED is "true")
     * @param string[] $projects      Not read: which projects Kelpie acts on is the `projects` table (ProjectModeService)
     * @param string   $ciProjectId   The default project ID (CI_PROJECT_ID)
     * @param string   $ciProjectPath The default project path (CI_PROJECT_PATH)
     * @param int      $claimTtl      Seconds a claim on a thread lasts
     * @param string   $gitBaseUrl    The base URL a project's git remote hangs from
     * @param string   $fixerModel    The fixer's model
     * @param string   $openRouterKey The OpenRouter API key
     * @param string   $composerAuth  COMPOSER_AUTH for the gate
     * @param int      $sweepMaxRuns  The most threads a sweep runs
     * @param string   $botUsername   The bot's username
     * @param string[] $skipAuthors   Authors the sweep ignores
     * @param string[] $skipBranches  Branch prefixes the sweep ignores
     */
    public function __construct(
        public string $apiUrl = '',
        public string $botToken = '',
        public string $apiToken = '',
        public bool $enabled = false,
        public array $projects = [self::DEFAULT_PROJECT],
        public string $ciProjectId = '',
        public string $ciProjectPath = '',
        public int $claimTtl = self::DEFAULT_CLAIM_TTL,
        public string $gitBaseUrl = '',
        public string $fixerModel = self::DEFAULT_MODEL,
        public string $openRouterKey = '',
        public string $composerAuth = '',
        public int $sweepMaxRuns = self::DEFAULT_SWEEP_RUNS,
        public string $botUsername = self::DEFAULT_BOT,
        public array $skipAuthors = [],
        public array $skipBranches = [],
    ) {
    }
}
