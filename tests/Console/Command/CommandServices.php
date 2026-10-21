<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Agent\ClaudeCliFixerAgent;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\FixerRunnerBuilderService;
use Kelpie\Service\Autofix\FixThreadService;
use Kelpie\Service\Autofix\RespondService;
use Kelpie\Service\Autofix\SweepRunService;
use Kelpie\Service\Triage\TriageDryRunService;
use Kelpie\Service\Triage\TriageService;
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

    /**
     * Constructor
     *
     * @param Logger          $logger   A logger stub
     * @param HttpClient      $http     An HTTP client stub; it is told to answer 404 to everything
     * @param SqlService      $sql      An SQL service stub
     * @param AutofixSettings $settings The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $http,
        SqlService $sql,
        private readonly AutofixSettings $settings,
    ) {
        $this->json    = new JsonService($this->logger);
        $factory       = new Psr17Factory();
        $this->jobs    = new AutofixJobService($this->logger, new AutofixJobSqlRepository($this->logger, $sql));
        $this->triage  = new TriageService(
            $this->logger,
            new OpenRouterClient($this->logger, $this->http, $factory, $factory, $this->json),
            new TriagePrompt($this->logger),
            new SafetyPolicy($this->logger),
            $this->json,
        );
        $this->gitlab  = new GitlabClientFactory($this->logger, $this->http, $factory, $factory, $this->json, $settings);
        $runner        = new ScriptedProcessRunner();
        $this->runners = new FixerRunnerBuilderService($this->logger, $this->gitlab, $this->triage, $this->jobs, $runner, new ClaudeCliFixerAgent($this->logger, $runner, 'test-key-not-real'), $settings);
    }

    /**
     * @return AutofixJobService
     */
    public function jobs(): AutofixJobService
    {
        return $this->jobs;
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
        return new FixThreadService($this->logger, $this->gitlab, $this->jobs, $this->runners, $this->settings);
    }

    /**
     * @return RespondService
     */
    public function respond(): RespondService
    {
        return new RespondService($this->logger, $this->gitlab, $this->triage, $this->settings);
    }

    /**
     * @return SweepRunService
     */
    public function sweep(): SweepRunService
    {
        return new SweepRunService($this->logger, $this->gitlab, $this->jobs, $this->settings);
    }

    /**
     * @return TriageDryRunService
     */
    public function triage(): TriageDryRunService
    {
        return new TriageDryRunService($this->logger, $this->http, new Psr17Factory(), $this->json, $this->triage, $this->settings);
    }
}
