<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Security\ContextScanner;
use Kelpie\Service\Autofix\IssueFixBuilderService;
use Kelpie\Service\Autofix\IssueFixPromptService;
use Kelpie\Service\Autofix\IssueProposeEditsService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Tests\Service\Triage\TriageLlms;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\IssueFixBuilderService
 *
 * What must hold:
 * - It builds a service each time, over its own checkout
 * - With the kill switch off the service it builds does nothing
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\IssueFixBuilderService
 */
final class IssueFixBuilderServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueFixBuilderService::class);
    }

    /**
     * Each call builds a service, and a disabled one does nothing
     *
     * @return void
     */
    public function testMakesAServiceThatHonoursTheKillSwitch(): void
    {
        $logger   = $this->createStub(Logger::class);
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'bot-token');
        $client   = new OpenRouterClient($logger, new ScriptedModelHttp([]), $factory, $factory, $json);
        $propose  = new ProposeEditsService($logger, $client, new ProposePrompt($logger, new ContextScanner($logger)), new LlmFamilyService($logger), $json, TriageLlms::make($logger));
        $gitlab   = new FakeFixGitlab();
        $projects = $this->createStub(ProjectReader::class);

        $builder = new IssueFixBuilderService(
            $logger,
            new GitlabClientFactory($logger, $gitlab, $factory, $factory, $json, $settings),
            new IssueProposeEditsService($logger, $client, new IssueFixPromptService($logger, new ContextScanner($logger)), $propose, $json),
            $this->createStub(ProcessRunner::class),
            $projects,
            new SpendCapService($logger, $projects, $this->createStub(ThreadOutcomeReader::class)),
            $this->createStub(ThreadOutcomeWriter::class),
            $settings,
            'reviewer',
        );

        $this->assertNotSame($builder->make(), $builder->make());
        $this->assertSame('skipped', $builder->make()->fix('org/proj', 7)->value);
        $this->assertSame([], $gitlab->requests());
    }
}
