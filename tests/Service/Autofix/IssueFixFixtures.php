<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Service\Autofix\IssueFixBuilderService;
use Kelpie\Service\Autofix\IssueFixPromptService;
use Kelpie\Service\Autofix\IssueProposeEditsService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Tests\Service\Triage\TriageLlms;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Builds an issue fix builder over stubs, for the tests that only need the worker loop to have one
 */
final class IssueFixFixtures extends TestCase
{
    /**
     * The builder: GitLab answers through the given client, the database and the processes are stubs
     *
     * @param Logger          $logger   Logger
     * @param HttpClient      $http     The client GitLab and the model are reached through
     * @param AutofixSettings $settings The settings
     * @param bool            $enrolled Whether the project reader knows org/proj (in propose mode)
     *
     * @return IssueFixBuilderService
     */
    public static function builder(Logger $logger, HttpClient $http, AutofixSettings $settings, bool $enrolled = false): IssueFixBuilderService
    {
        $self     = new self('builder');
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $client   = new OpenRouterClient($logger, $http, $factory, $factory, $json);
        $propose  = new ProposeEditsService($logger, $client, new ProposePrompt($logger), new LlmFamilyService($logger), $json, TriageLlms::make($logger));
        $projects = $self->createStub(ProjectReader::class);
        if ($enrolled) {
            $projects->method('getByPath')->willReturn(new Project('org/proj', ProjectMode::Propose));
        }

        return new IssueFixBuilderService(
            $logger,
            new GitlabClientFactory($logger, $http, $factory, $factory, $json, $settings),
            new IssueProposeEditsService($logger, $client, new IssueFixPromptService($logger), $propose, $json),
            $self->createStub(ProcessRunner::class),
            $projects,
            new SpendCapService($logger, $projects, $self->createStub(ThreadOutcomeReader::class)),
            $self->createStub(ThreadOutcomeWriter::class),
            $settings,
        );
    }
}
