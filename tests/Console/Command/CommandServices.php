<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\FixerRunnerBuilderService;
use Kelpie\Service\Autofix\FixThreadService;
use Kelpie\Service\Autofix\ProposalReactionBuilderService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Autofix\RespondService;
use Kelpie\Service\Autofix\SweepRunService;
use Kelpie\Service\Project\ProjectModeService;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageDryRunService;
use Kelpie\Service\Triage\TriageService;
use Kelpie\Tests\Repository\Project\InMemoryProjects;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;

/**
 * Real services over a network that answers 404 to everything, for the command tests
 *
 * The commands only parse, hand over and print, so these tests drive the paths a service refuses before it
 * makes a request; the services' own tests cover what they do with GitLab.
 */
final class CommandServices
{
    private JsonService $json;

    private AutofixJobService $jobs;

    private TriageService $triage;

    private GitlabClientFactory $gitlab;

    private FixerRunnerBuilderService $runners;

    private ProposalReactionBuilderService $reactions;

    private ProjectModeService $modes;

    /**
     * Constructor
     *
     * @param Logger                      $logger   A logger stub
     * @param HttpClient                  $http     An HTTP client stub; it is told to answer 404 to everything
     * @param SqlService                  $sql      An SQL service stub
     * @param AutofixSettings             $settings The settings
     * @param ?array<string, ProjectMode> $modes    The projects table; ubixsys/ubixkelpie and org/proj in propose when null
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $http,
        SqlService $sql,
        private readonly AutofixSettings $settings,
        ?array $modes = null,
    ) {
        $modes         ??= ['ubixsys/ubixkelpie' => ProjectMode::Propose, 'org/proj' => ProjectMode::Propose];
        $this->modes     = InMemoryProjects::service($modes);
        $this->json      = new JsonService($this->logger);
        $factory         = new Psr17Factory();
        $this->jobs      = new AutofixJobService($this->logger, new AutofixJobSqlRepository($this->logger, $sql));
        $this->triage    = new TriageService(
            $this->logger,
            new OpenRouterClient($this->logger, $this->http, $factory, $factory, $this->json),
            new TriagePrompt($this->logger),
            new SafetyPolicy($this->logger),
            $this->json,
            new LlmFamilyService($this->logger),
        );
        $this->gitlab    = new GitlabClientFactory($this->logger, $this->http, $factory, $factory, $this->json, $settings);
        $open            = new OpenRouterClient($this->logger, $this->http, $factory, $factory, $this->json);
        $propose         = new ProposeEditsService($this->logger, $open, new ProposePrompt($this->logger), new LlmFamilyService($this->logger), $this->json);
        $this->runners   = new FixerRunnerBuilderService($this->logger, $this->gitlab, $this->triage, $this->jobs, new ScriptedProcessRunner(), $propose, new ThreadOutcomeSqlRepository($this->logger, $sql), new SpendCapService($this->logger, new InMemoryProjects($modes), new ThreadOutcomeSqlRepository($this->logger, $sql)), $settings);
        $outcomes        = new ThreadOutcomeSqlRepository($this->logger, $sql);
        $this->reactions = new ProposalReactionBuilderService($this->logger, $this->gitlab, $this->jobs, new ScriptedProcessRunner(), $outcomes, $outcomes, $this->json, $settings, $this->modes);
    }

    /**
     * @return ProjectModeService
     */
    public function modes(): ProjectModeService
    {
        return $this->modes;
    }

    /**
     * @return AutofixJobService
     */
    public function jobs(): AutofixJobService
    {
        return $this->jobs;
    }

    /**
     * @return ProposalReactionBuilderService
     */
    public function reactions(): ProposalReactionBuilderService
    {
        return $this->reactions;
    }

    /**
     * @return FixerRunnerBuilderService
     */
    public function runners(): FixerRunnerBuilderService
    {
        return $this->runners;
    }

    /**
     * @return FixThreadService
     */
    public function fix(): FixThreadService
    {
        return new FixThreadService($this->logger, $this->gitlab, $this->jobs, $this->runners, $this->settings, $this->modes);
    }

    /**
     * @return RespondService
     */
    public function respond(): RespondService
    {
        return new RespondService($this->logger, $this->gitlab, $this->triage, $this->settings, $this->modes);
    }

    /**
     * @return SweepRunService
     */
    public function sweep(): SweepRunService
    {
        return new SweepRunService($this->logger, $this->gitlab, $this->jobs, $this->settings, $this->modes);
    }

    /**
     * @return TriageDryRunService
     */
    public function triage(): TriageDryRunService
    {
        return new TriageDryRunService($this->logger, $this->http, new Psr17Factory(), $this->json, $this->triage, $this->settings);
    }
}
