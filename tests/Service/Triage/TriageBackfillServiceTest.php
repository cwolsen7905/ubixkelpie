<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Service\Triage\TriageBackfillService;
use Kelpie\Tests\GitLab\FakeIssueListGitlab;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Kelpie\Tests\Repository\Project\InMemoryProjects;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\TriageBackfillService
 *
 * What must hold:
 * - Only open issues with no kelpie:: label that are not confidential, bot-authored or opted out are queued
 * - A dry run (the default) lists and writes nothing; only GitLab reads are made either way
 * - The limit caps what one run queues, and an issue that already has a job is skipped and does not count
 * - A re-run queues nothing twice
 * - It refuses without settings, outside the project list, with a bad limit, and (when writing) with autofix off
 *
 * @coversDefaultClass \Kelpie\Service\Triage\TriageBackfillService
 */
final class TriageBackfillServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageBackfillService::class);
    }

    /**
     * A dry run lists what it would queue and writes nothing
     *
     * @return void
     */
    public function testDryRunListsAndWritesNothing(): void
    {
        $queue  = new InMemoryIssueQueue();
        $result = $this->service($queue, $this->gitlab())->backfill();

        $this->assertTrue($result->success);
        $this->assertSame(['would queue org/proj#1', 'would queue org/proj#6', 'would queue org/other#1', 'Dry run: 3 issue(s) would be queued; nothing was written. Add --no-dry-run to queue them.'], $result->lines);
        $this->assertSame([], $queue->enqueued());
    }

    /**
     * Writing queues exactly the issues that need triage
     *
     * @return void
     */
    public function testQueuesOnlyTheIssuesThatNeedTriage(): void
    {
        $queue  = new InMemoryIssueQueue();
        $result = $this->service($queue, $this->gitlab())->backfill(dryRun: false);

        $this->assertTrue($result->success);
        $this->assertSame(['org/proj#1', 'org/proj#6', 'org/other#1'], $queue->enqueued());
        $this->assertSame('3 issue(s) queued for the worker.', $result->lines[3]);
    }

    /**
     * Only GitLab list reads are made: nothing is posted, whichever mode
     *
     * @return void
     */
    public function testItNeverWritesToGitlab(): void
    {
        $gitlab = $this->gitlab();

        $this->service(new InMemoryIssueQueue(), $gitlab)->backfill(dryRun: false);

        foreach ($gitlab->requests() as $request) {
            $this->assertStringStartsWith('GET ', $request);
            $this->assertStringContainsString('/issues?state=opened', $request);
        }
    }

    /**
     * The project option narrows the run to one project
     *
     * @return void
     */
    public function testProjectLimitsTheRun(): void
    {
        $queue = new InMemoryIssueQueue();

        $this->service($queue, $this->gitlab())->backfill('org/other', dryRun: false);

        $this->assertSame(['org/other#1'], $queue->enqueued());
    }

    /**
     * The limit stops the run and says so
     *
     * @return void
     */
    public function testTheLimitCapsTheRun(): void
    {
        $queue  = new InMemoryIssueQueue();
        $result = $this->service($queue, $this->gitlab())->backfill(limit: '2', dryRun: false);

        $this->assertSame(['org/proj#1', 'org/proj#6'], $queue->enqueued());
        $this->assertContains('org/other: stopped at the limit of 2; run again for the rest.', $result->lines);
        $lines = $result->lines;
        $this->assertSame('2 issue(s) queued for the worker.', end($lines));
    }

    /**
     * A re-run adds nothing, and issues that already have a job do not use up the limit
     *
     * @return void
     */
    public function testARerunMovesOnAndAddsNothingTwice(): void
    {
        $queue   = new InMemoryIssueQueue();
        $service = $this->service($queue, $this->gitlab());

        $service->backfill(limit: '1', dryRun: false);
        $service->backfill(limit: '1', dryRun: false);
        $service->backfill(limit: '9', dryRun: false);
        $again = $service->backfill(limit: '9', dryRun: false);

        $this->assertSame(['org/proj#1', 'org/proj#6', 'org/other#1'], $queue->enqueued());
        $lines = $again->lines;
        $this->assertSame('0 issue(s) queued for the worker.', end($lines));
    }

    /**
     * Only projects that are not off are listed, and one that is off or has no row is refused by name
     *
     * @return void
     */
    public function testOnlyEnrolledProjectsAreBackfilled(): void
    {
        $queue = new InMemoryIssueQueue();
        $modes = ['org/proj' => ProjectMode::Propose, 'org/other' => ProjectMode::Off];

        $all = $this->service($queue, $this->gitlab(), null, $modes)->backfill(dryRun: false);
        $off = $this->service($queue, $this->gitlab(), null, $modes)->backfill('org/other', dryRun: false);

        $this->assertTrue($all->success);
        $this->assertSame(['org/proj#1', 'org/proj#6'], $queue->enqueued());
        $this->assertFalse($off->success);
        $this->assertTrue($off->isError);
        $this->assertStringContainsString('not enrolled', $off->lines[0]);
    }

    /**
     * Bad input and missing settings are errors, and nothing is read or written
     *
     * @return void
     */
    public function testRefusals(): void
    {
        $queue  = new InMemoryIssueQueue();
        $gitlab = $this->gitlab();

        $cases = [
            $this->service($queue, $gitlab)->backfill(limit: '0'),
            $this->service($queue, $gitlab)->backfill(limit: 'ten'),
            $this->service($queue, $gitlab)->backfill('org/unlisted'),
            $this->service($queue, $gitlab, new AutofixSettings())->backfill(),
            $this->service($queue, $gitlab, $this->settings(enabled: false))->backfill(dryRun: false),
        ];

        foreach ($cases as $result) {
            $this->assertFalse($result->success);
            $this->assertTrue($result->isError);
        }
        $this->assertSame([], $queue->enqueued());
        $this->assertSame([], $gitlab->requests());
    }

    /**
     * With autofix off a dry run still works
     *
     * @return void
     */
    public function testDryRunWorksWithAutofixOff(): void
    {
        $result = $this->service(new InMemoryIssueQueue(), $this->gitlab(), $this->settings(enabled: false))->backfill();

        $this->assertTrue($result->success);
        $this->assertSame('would queue org/proj#1', $result->lines[0]);
    }

    /**
     * An unreadable project is reported and the others still run
     *
     * @return void
     */
    public function testAnUnreadableProjectIsReported(): void
    {
        $queue  = new InMemoryIssueQueue();
        $result = $this->service($queue, $this->gitlab()->failing('org/proj'))->backfill(dryRun: false);

        $this->assertFalse($result->success);
        $this->assertContains('org/proj: the open issues could not be read.', $result->lines);
        $this->assertSame(['org/other#1'], $queue->enqueued());
    }

    /**
     * A queue write that throws is reported, not thrown
     *
     * @return void
     */
    public function testAQueueFailureIsReported(): void
    {
        $result = $this->service((new InMemoryIssueQueue())->enqueueThrows(), $this->gitlab())->backfill('org/other', dryRun: false);

        $this->assertFalse($result->success);
        $this->assertContains('org/other#1: could not be queued.', $result->lines);
    }

    /**
     * Two projects: org/proj has issues 1 (needs triage), 2 (confidential), 3 (bot), 4 (opted out), 5 (labelled), 6 (needs
     * triage); org/other has issue 1
     *
     * @return FakeIssueListGitlab
     */
    private function gitlab(): FakeIssueListGitlab
    {
        return (new FakeIssueListGitlab())
            ->withIssue('org/proj', 1)
            ->withIssue('org/proj', 2, confidential: true)
            ->withIssue('org/proj', 3, 'kelpie-bot')
            ->withIssue('org/proj', 4, labels: ['kelpie:skip'])
            ->withIssue('org/proj', 5, labels: ['bug', 'kelpie::needs-human'])
            ->withIssue('org/proj', 6, labels: ['bug'])
            ->withIssue('org/other', 1);
    }

    /**
     * @param bool $enabled Whether autofix is on
     *
     * @return AutofixSettings
     */
    private function settings(bool $enabled = true): AutofixSettings
    {
        return new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: $enabled);
    }

    /**
     * @param InMemoryIssueQueue          $queue    The queue
     * @param FakeIssueListGitlab         $gitlab   The fake GitLab
     * @param ?AutofixSettings            $settings The settings; enabled, when null
     * @param ?array<string, ProjectMode> $modes    The projects table; org/proj and org/other in propose when null
     *
     * @return TriageBackfillService
     */
    private function service(InMemoryIssueQueue $queue, FakeIssueListGitlab $gitlab, ?AutofixSettings $settings = null, ?array $modes = null): TriageBackfillService
    {
        $modes    ??= ['org/proj' => ProjectMode::Propose, 'org/other' => ProjectMode::Push];
        $settings ??= $this->settings();
        $logger     = $this->createStub(Logger::class);
        $factory    = new Psr17Factory();

        return new TriageBackfillService($logger, new GitlabClientFactory($logger, $gitlab, $factory, $factory, new JsonService($logger), $settings), $queue, $queue, $settings, InMemoryProjects::service($modes));
    }
}
