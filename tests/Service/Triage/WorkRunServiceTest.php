<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\IssueTriagePrompt;
use Kelpie\Service\Triage\WorkRunService;
use Kelpie\Tests\Console\Command\CommandServices;
use Kelpie\Tests\GitLab\FakeIssueGitlab;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Kelpie\Tests\Repository\Project\InMemoryProjects;
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
 * - It claims only for projects whose mode is not off, and re-reads the table on every pass
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
     * The claim is for the projects that are enrolled now: propose and push, not off, not unlisted
     *
     * @return void
     */
    public function testClaimsOnlyForEnrolledProjects(): void
    {
        $queue = new InMemoryIssueQueue();

        $this->service($queue, new FakeIssueGitlab(), null, ['org/a' => ProjectMode::Propose, 'org/b' => ProjectMode::Push, 'org/off' => ProjectMode::Off])->run('1', '1');

        $this->assertNotSame([], $queue->claims());
        $this->assertSame(['org/a,org/b'], array_values(array_unique($queue->claims())));
    }

    /**
     * No row, or off, means no project is claimed for
     *
     * @return void
     */
    public function testClaimsNothingWhenNoProjectIsEnrolled(): void
    {
        $queue = new InMemoryIssueQueue([new IssueTriageJob(1, 'org/proj', 7, 0)]);

        $this->service($queue, new FakeIssueGitlab(), null, ['org/proj' => ProjectMode::Off])->run('1', '1');

        $this->assertSame([''], array_values(array_unique($queue->claims())));
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
        return new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: $enabled, openRouterKey: 'key-not-real');
    }

    /**
     * Build the service over a queue and a fake GitLab, with a model that always says needs_human and no waiting between polls
     *
     * @param InMemoryIssueQueue          $queue    The queue
     * @param FakeIssueGitlab             $gitlab   The fake GitLab
     * @param ?AutofixSettings            $settings The settings; a working, enabled set when null
     * @param ?array<string, ProjectMode> $modes    The projects table; org/proj in propose when null
     *
     * @return WorkRunService
     */
    private function service(InMemoryIssueQueue $queue, FakeIssueGitlab $gitlab, ?AutofixSettings $settings = null, ?array $modes = null): WorkRunService
    {
        $modes    ??= ['org/proj' => ProjectMode::Propose];
        $settings ??= $this->settings();
        $logger     = $this->createStub(Logger::class);
        $json       = new JsonService($logger);
        $factory    = new Psr17Factory();
        $model      = $this->createStub(HttpClient::class);
        $model->method('sendRequest')->willReturn(new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => '{"class": "needs_human", "reason": "unclear"}']]]])));

        $services = new CommandServices($logger, $gitlab, $this->createStub(SqlService::class), $settings, $modes);

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
            $services->reactions(),
            InMemoryProjects::service($modes),
            0,
        );
    }
}
