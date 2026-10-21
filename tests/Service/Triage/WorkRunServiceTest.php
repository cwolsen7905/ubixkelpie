<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\IssueTriagePrompt;
use Kelpie\Service\Triage\WorkRunService;
use Kelpie\Tests\Console\Command\CommandServices;
use Kelpie\Tests\GitLab\FakeIssueGitlab;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\WorkRunService
 *
 * What must hold:
 * - Without GitLab or OpenRouter settings it refuses before touching the queue
 * - It takes queued jobs until its job limit and reports how many
 * - With autofix off it claims nothing and stops at its time limit
 *
 * @coversDefaultClass \Kelpie\Service\Triage\WorkRunService
 */
final class WorkRunServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(WorkRunService::class);
    }

    /**
     * Missing settings are refused as an error
     *
     * @return void
     */
    public function testRefusesMissingSettings(): void
    {
        $queue = new InMemoryIssueQueue();

        foreach ([new AutofixSettings(), new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real'), new AutofixSettings(botToken: 'tok-not-real', openRouterKey: 'k')] as $settings) {
            $result = $this->service($queue, new FakeIssueGitlab(), $settings)->run('1');

            $this->assertFalse($result->success);
            $this->assertTrue($result->isError);
        }
        $this->assertSame([], $queue->claims());
    }

    /**
     * It works through the queue up to the job limit
     *
     * @return void
     */
    public function testTakesJobsUpToTheLimit(): void
    {
        $queue = new InMemoryIssueQueue([new IssueTriageJob(1, 'org/proj', 7, 0), new IssueTriageJob(2, 'org/proj', 7, 0), new IssueTriageJob(3, 'org/proj', 7, 0)]);

        $result = $this->service($queue, new FakeIssueGitlab())->run('2', '60');

        $this->assertTrue($result->success);
        $this->assertSame(['Worker stopped after 2 job(s).'], $result->lines);
        $this->assertSame(['lock 1 0', 'done 1 needs_human', 'lock 2 0', 'done 2 needs_human'], $queue->settled());
    }

    /**
     * Disabled, it claims nothing, and the time limit ends the run
     *
     * @return void
     */
    public function testDisabledIdlesUntilTheTimeLimit(): void
    {
        $queue = new InMemoryIssueQueue([new IssueTriageJob(1, 'org/proj', 7, 0)]);

        $result = $this->service($queue, new FakeIssueGitlab(), $this->settings(enabled: false))->run('5', '1');

        $this->assertSame(['Worker stopped after 0 job(s).'], $result->lines);
        $this->assertSame([], $queue->claims());
    }

    /**
     * Set a dummy key
     *
     * @return void
     */
    protected function setUp(): void
    {
        putenv('OPENROUTER_API_KEY=test-key-not-real');
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
    }

    /**
     * @param bool $enabled Whether autofix is on
     *
     * @return AutofixSettings
     */
    private function settings(bool $enabled = true): AutofixSettings
    {
        return new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: $enabled, projects: ['org/proj'], openRouterKey: 'key-not-real');
    }

    /**
     * Build the service over a queue and a fake GitLab, with a model that always says needs_human and no waiting between polls
     *
     * @param InMemoryIssueQueue $queue    The queue
     * @param FakeIssueGitlab    $gitlab   The fake GitLab
     * @param ?AutofixSettings   $settings The settings; a working, enabled set when null
     *
     * @return WorkRunService
     */
    private function service(InMemoryIssueQueue $queue, FakeIssueGitlab $gitlab, ?AutofixSettings $settings = null): WorkRunService
    {
        $settings ??= $this->settings();
        $logger     = $this->createStub(Logger::class);
        $json       = new JsonService($logger);
        $factory    = new Psr17Factory();
        $model      = $this->createStub(HttpClient::class);
        $model->method('sendRequest')->willReturn(new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => '{"class": "needs_human", "reason": "unclear"}']]]])));

        $services = new CommandServices($logger, $gitlab, $this->createStub(SqlService::class), $settings);

        return new WorkRunService(
            $logger,
            new GitlabClientFactory($logger, $gitlab, $factory, $factory, $json, $settings),
            $queue,
            $queue,
            new OpenRouterClient($logger, $model, $factory, $factory, $json),
            new IssueTriagePrompt($logger),
            $json,
            $settings,
            $services->jobs(),
            $services->sweep(),
            $services->runners(),
            0,
        );
    }
}
