<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\EditApplyService;
use Kelpie\Service\Autofix\IssueFixPromptService;
use Kelpie\Service\Autofix\IssueFixService;
use Kelpie\Service\Autofix\IssueFixWorkerService;
use Kelpie\Service\Autofix\IssueGateService;
use Kelpie\Service\Autofix\IssueProposeEditsService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\IssueFixWorkerService
 *
 * What must hold:
 * - With autofix off nothing is claimed, so the jobs stay pending (the kill switch)
 * - Only the enrolled projects are offered to the claim
 * - Any outcome but Retry settles the job with the outcome; Retry is tried once more after five minutes, then given up on
 * - An exception in the fix or the claim never escapes the loop, and a lost lock race is not a step
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\IssueFixWorkerService
 */
final class IssueFixWorkerServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string NOW = '2026-10-19 12:00:00 UTC';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueFixWorkerService::class);
    }

    /**
     * Disabled, the queue is not even asked
     *
     * @return void
     */
    public function testDisabledClaimsNothing(): void
    {
        $queue = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(1, 'org/proj', 7, 0)]);

        $this->assertFalse($this->worker($queue, new FakeFixGitlab())->step(false, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame([], $queue->claims());
    }

    /**
     * The claim is offered the enrolled projects, and an empty queue is not a step
     *
     * @return void
     */
    public function testAnEmptyQueueIsIdle(): void
    {
        $queue = new InMemoryIssueQueue();

        $this->assertFalse($this->worker($queue, new FakeFixGitlab())->step(true, ['org/proj', 'org/other'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['fix:org/proj,org/other'], $queue->claims());
    }

    /**
     * A settled job is done with its outcome
     *
     * @return void
     */
    public function testASettledJobIsDone(): void
    {
        $queue = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)]);

        $this->assertTrue($this->worker($queue, new FakeFixGitlab([]))->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['lock 4 0', 'done 4 skipped'], $queue->settled());
    }

    /**
     * A Retry is put back for five minutes, and the second one is given up on
     *
     * @return void
     */
    public function testRetryThenGiveUp(): void
    {
        $unreadable = (new FakeFixGitlab())->failIssue();
        $first      = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)]);
        $second     = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 1)]);

        $this->assertTrue($this->worker($first, $unreadable)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertTrue($this->worker($second, $unreadable)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));

        $this->assertSame('retry 4 12:05:00 nothing could be done this time', $first->settled()[1]);
        $this->assertStringStartsWith('failed 4 nothing could be done after 2 tries', $second->settled()[1]);
    }

    /**
     * An exception in the fix is a Retry, never thrown out of the loop
     *
     * @return void
     */
    public function testAThrowingFixIsARetry(): void
    {
        $queue = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)]);

        $this->assertTrue($this->worker($queue, new FakeFixGitlab(), throwing: true)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertStringStartsWith('retry 4', $queue->settled()[1]);
    }

    /**
     * A lost lock race is not a step, and a claim that throws is swallowed
     *
     * @return void
     */
    public function testLostRaceAndClaimFailure(): void
    {
        $lost = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)])->loseTheLock();
        $this->assertFalse($this->worker($lost, new FakeFixGitlab())->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));

        $broken = $this->createStub(IssueTriageJobReader::class);
        $broken->method('getDueFixJob')->willThrowException(new RuntimeException('column missing'));
        $worker = new IssueFixWorkerService($this->createStub(Logger::class), $broken, new InMemoryIssueQueue(), $this->fixer(new FakeFixGitlab()));
        $this->assertFalse($worker->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
    }

    /**
     * @param InMemoryIssueQueue $queue    The queue
     * @param FakeFixGitlab      $gitlab   The fake GitLab
     * @param bool               $throwing Whether reading the project throws
     *
     * @return IssueFixWorkerService
     */
    private function worker(InMemoryIssueQueue $queue, FakeFixGitlab $gitlab, bool $throwing = false): IssueFixWorkerService
    {
        return new IssueFixWorkerService($this->createStub(Logger::class), $queue, $queue, $this->fixer($gitlab, $throwing));
    }

    /**
     * An issue fix service whose only live parts are the issue read and the project read
     *
     * @param FakeFixGitlab $gitlab   The fake GitLab
     * @param bool          $throwing Whether reading the project throws
     *
     * @return IssueFixService
     */
    private function fixer(FakeFixGitlab $gitlab, bool $throwing = false): IssueFixService
    {
        $logger   = $this->createStub(Logger::class);
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'bot-token', enabled: true, gitBaseUrl: '/nonexistent');
        $client   = new OpenRouterClient($logger, new ScriptedModelHttp([]), $factory, $factory, $json);
        $propose  = new ProposeEditsService($logger, $client, new ProposePrompt($logger), new LlmFamilyService($logger), $json);
        $projects = $this->createStub(ProjectReader::class);
        if ($throwing) {
            $projects->method('getByPath')->willThrowException(new RuntimeException('database down'));
        } else {
            $projects->method('getByPath')->willReturn(new Project('org/proj', ProjectMode::Propose));
        }
        $runner = new ProcOpenProcessRunner($logger);

        return new IssueFixService(
            $logger,
            new GitlabClientFactory($logger, $gitlab, $factory, $factory, $json, $settings),
            new IssueProposeEditsService($logger, $client, new IssueFixPromptService($logger), $propose, $json),
            new EditApplyService($logger),
            new SafetyPolicy($logger),
            new IssueGateService($logger, $runner, new GateRunner($logger, $runner)),
            new Checkout($logger, $runner, 'tok'),
            new SpendCapService($logger, $projects, $this->createStub(ThreadOutcomeReader::class)),
            $projects,
            $this->createStub(ThreadOutcomeWriter::class),
            $settings,
        );
    }
}
