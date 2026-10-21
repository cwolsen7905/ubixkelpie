<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\WorkCommand;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\IssueTriagePrompt;
use Kelpie\Service\Triage\WorkRunService;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\WorkCommand
 *
 * What must hold:
 * - It is named kelpie:work
 * - What the service refuses is printed as an error and exits 1
 * - Its options reach the service and its lines are printed
 *
 * @coversDefaultClass \Kelpie\Console\Command\WorkCommand
 */
final class WorkCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(WorkCommand::class);
    }

    /**
     * It is discovered as kelpie:work
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:work', $this->command(new AutofixSettings())->getName());
    }

    /**
     * Missing settings are printed as an error and the exit code is 1
     *
     * @return void
     */
    public function testPrintsRefusalAsError(): void
    {
        $tester = new CommandTester($this->command(new AutofixSettings()));

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('must be set', $tester->getDisplay());
    }

    /**
     * Configured and with nothing queued, a bounded run ends cleanly
     *
     * @return void
     */
    public function testABoundedRunEndsCleanly(): void
    {
        $tester = new CommandTester($this->command(new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: false, openRouterKey: 'key-not-real')));

        $this->assertSame(0, $tester->execute(['--max-jobs' => '1', '--max-seconds' => '1']));
        $this->assertStringContainsString('Worker stopped after 0 job(s).', $tester->getDisplay());
    }

    /**
     * Build the command over the real service, an empty queue and a network that answers 404
     *
     * @param AutofixSettings $settings The settings
     *
     * @return WorkCommand
     */
    private function command(AutofixSettings $settings): WorkCommand
    {
        $logger  = $this->createStub(Logger::class);
        $json    = new JsonService($logger);
        $factory = new Psr17Factory();
        $http    = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturn(new Psr7Response(404));

        $services = new CommandServices($logger, $http, $this->createStub(SqlService::class), $settings);

        return new WorkCommand($logger, new WorkRunService(
            $logger,
            new GitlabClientFactory($logger, $http, $factory, $factory, $json, $settings),
            new InMemoryIssueQueue(),
            new InMemoryIssueQueue(),
            new OpenRouterClient($logger, $http, $factory, $factory, $json),
            new IssueTriagePrompt($logger),
            $json,
            $settings,
            $services->jobs(),
            $services->sweep(),
            $services->runners(),
            0,
        ));
    }
}
