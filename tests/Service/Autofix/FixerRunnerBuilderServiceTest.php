<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\FixerRunnerBuilderService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageService;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\FixerRunnerBuilderService
 *
 * What must hold:
 * - It builds a runner for each project, each a new object
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\FixerRunnerBuilderService
 */
final class FixerRunnerBuilderServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FixerRunnerBuilderService::class);
    }

    /**
     * A runner per call, enabled or not
     *
     * @return void
     */
    public function testMakesARunnerPerCall(): void
    {
        $logger   = $this->createStub(Logger::class);
        $http     = $this->createStub(HttpClient::class);
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'tok-bot', gitBaseUrl: 'https://gitlab.test');
        $runner   = new ScriptedProcessRunner();
        $triage   = new TriageService($logger, new OpenRouterClient($logger, $http, $factory, $factory, $json), new TriagePrompt($logger), new SafetyPolicy($logger), $json, new LlmFamilyService($logger));
        $jobs     = new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $this->createStub(SqlService::class)));
        $runners  = new FixerRunnerBuilderService(
            $logger,
            new GitlabClientFactory($logger, $http, $factory, $factory, $json, $settings),
            $triage,
            $jobs,
            $runner,
            new ProposeEditsService($logger, new OpenRouterClient($logger, $http, $factory, $factory, $json), new ProposePrompt($logger), new LlmFamilyService($logger), $json),
            new ThreadOutcomeSqlRepository($logger, $this->createStub(SqlService::class)),
            $settings,
        );

        $this->assertNotSame($runners->make(true, 'ubixsys/ubixkelpie'), $runners->make(true, 'ubixsys/ubixkelpie'));
        $this->assertNotSame($runners->make(false, 'a/b'), $runners->make(true, 'a/b'));
    }
}
