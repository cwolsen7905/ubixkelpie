<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use DateTimeImmutable;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\GitLab\IssueClient;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\IssueTriagePrompt;
use Kelpie\Service\Triage\IssueTriageService;
use Kelpie\Service\Triage\IssueTriageWorkerService;
use Kelpie\Tests\GitLab\FakeIssueGitlab;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\IssueTriageWorkerService
 *
 * What must hold:
 * - With autofix off nothing is claimed, so the jobs stay pending (the kill switch)
 * - Only the enrolled projects are offered to the claim
 * - A triaged or skipped job is done; a failed one is retried after 1 and then 5 minutes and given up on at the third attempt
 * - An exception in triage or in the claim never escapes the loop
 *
 * @coversDefaultClass \Kelpie\Service\Triage\IssueTriageWorkerService
 */
final class IssueTriageWorkerServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string NOW = '2026-10-19 12:00:00 UTC';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueTriageWorkerService::class);
    }

    /**
     * Disabled, the queue is not even asked
     *
     * @return void
     */
    public function testDisabledClaimsNothing(): void
    {
        $queue = new InMemoryIssueQueue([new IssueTriageJob(1, 'org/proj', 7, 0)]);

        $this->assertFalse($this->worker($queue, new FakeIssueGitlab())->step(false, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame([], $queue->claims());
        $this->assertSame([], $queue->settled());
    }

    /**
     * The claim is offered the enrolled projects, and an empty queue is not a step
     *
     * @return void
     */
    public function testAnEmptyQueueIsIdle(): void
    {
        $queue = new InMemoryIssueQueue();

        $this->assertFalse($this->worker($queue, new FakeIssueGitlab())->step(true, ['org/proj', 'org/other'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['org/proj,org/other'], $queue->claims());
    }

    /**
     * A triaged job is done with its class
     *
     * @return void
     */
    public function testATriagedJobIsDone(): void
    {
        $queue  = new InMemoryIssueQueue([new IssueTriageJob(4, 'org/proj', 7, 0)]);
        $gitlab = new FakeIssueGitlab();

        $this->assertTrue($this->worker($queue, $gitlab)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['lock 4 0', 'done 4 needs_human'], $queue->settled());
        $this->assertSame(['kelpie::needs-human'], $gitlab->labels());
    }

    /**
     * A skipped job is done with no class
     *
     * @return void
     */
    public function testASkippedJobIsDoneWithoutAClass(): void
    {
        $queue = new InMemoryIssueQueue([new IssueTriageJob(4, 'org/proj', 7, 0)]);

        $this->worker($queue, new FakeIssueGitlab(confidential: true))->step(true, ['org/proj'], new DateTimeImmutable(self::NOW));

        $this->assertSame(['lock 4 0', 'done 4 -'], $queue->settled());
    }

    /**
     * Failures back off 1 then 5 minutes, and the third attempt is the last
     *
     * @return void
     */
    public function testFailuresBackOffThenGiveUp(): void
    {
        $queue  = new InMemoryIssueQueue([
            new IssueTriageJob(4, 'org/proj', 7, 0),
            new IssueTriageJob(4, 'org/proj', 7, 1),
            new IssueTriageJob(4, 'org/proj', 7, 2),
        ]);
        $worker = $this->worker($queue, (new FakeIssueGitlab())->failing('GET'));

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->assertTrue($worker->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        }

        $this->assertSame([
            'lock 4 0', 'retry 4 12:01:00 issue could not be read',
            'lock 4 1', 'retry 4 12:05:00 issue could not be read',
            'lock 4 2', 'failed 4 issue could not be read',
        ], $queue->settled());
    }

    /**
     * An exception in the claim is logged and the step is idle
     *
     * @return void
     */
    public function testAClaimFailureIsContained(): void
    {
        $queue = new InMemoryIssueQueue();
        $queue->claimThrows();

        $this->assertFalse($this->worker($queue, new FakeIssueGitlab())->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
    }

    /**
     * Losing the lock to another worker means nothing is triaged and nothing is settled
     *
     * @return void
     */
    public function testALostLockTriagesNothing(): void
    {
        $queue  = new InMemoryIssueQueue([new IssueTriageJob(4, 'org/proj', 7, 0)]);
        $gitlab = new FakeIssueGitlab();

        $this->assertFalse($this->worker($queue->loseTheLock(), $gitlab)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['lock 4 0'], $queue->settled());
        $this->assertSame([], $gitlab->requests());
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
     * Build the worker over the queue and a fake GitLab, with a model that always says needs_human
     *
     * @param InMemoryIssueQueue $queue  The queue
     * @param FakeIssueGitlab    $gitlab The fake GitLab
     *
     * @return IssueTriageWorkerService
     */
    private function worker(InMemoryIssueQueue $queue, FakeIssueGitlab $gitlab): IssueTriageWorkerService
    {
        $logger  = $this->createStub(Logger::class);
        $json    = new JsonService($logger);
        $factory = new Psr17Factory();
        $model   = $this->createStub(HttpClient::class);
        $model->method('sendRequest')->willReturn(new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => '{"class": "needs_human", "reason": "unclear"}']]]])));

        $triage = new IssueTriageService(
            $logger,
            new IssueClient($logger, $gitlab, $factory, $factory, 'https://gitlab.test/api/v4', $json),
            new OpenRouterClient($logger, $model, $factory, $factory, $json),
            new IssueTriagePrompt($logger),
            $json,
        );

        return new IssueTriageWorkerService($logger, $queue, $queue, $triage);
    }
}
