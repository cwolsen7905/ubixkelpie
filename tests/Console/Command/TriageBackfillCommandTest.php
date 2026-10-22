<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\TriageBackfillCommand;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Service\Triage\TriageBackfillService;
use Kelpie\Tests\GitLab\FakeIssueListGitlab;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Kelpie\Tests\Repository\Project\InMemoryProjects;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\TriageBackfillCommand
 *
 * What must hold:
 * - It is named kelpie:triage-backfill and is a dry run unless --no-dry-run is given
 * - --project and --limit reach the service
 * - What the service refuses is printed as an error and exits 1
 *
 * @coversDefaultClass \Kelpie\Console\Command\TriageBackfillCommand
 */
final class TriageBackfillCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageBackfillCommand::class);
    }

    /**
     * It is discovered as kelpie:triage-backfill
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:triage-backfill', $this->command(new InMemoryIssueQueue())->getName());
    }

    /**
     * With no options it is a dry run and queues nothing
     *
     * @return void
     */
    public function testDryRunIsTheDefault(): void
    {
        $queue  = new InMemoryIssueQueue();
        $tester = new CommandTester($this->command($queue));

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('would queue org/proj#1', $tester->getDisplay());
        $this->assertSame([], $queue->enqueued());
    }

    /**
     * --no-dry-run with --project and --limit queues just that
     *
     * @return void
     */
    public function testNoDryRunQueuesWithinTheLimit(): void
    {
        $queue  = new InMemoryIssueQueue();
        $tester = new CommandTester($this->command($queue));

        $this->assertSame(0, $tester->execute(['--no-dry-run' => true, '--project' => 'org/proj', '--limit' => '1']));
        $this->assertSame(['org/proj#1'], $queue->enqueued());
        $this->assertStringContainsString('1 issue(s) queued for the worker.', $tester->getDisplay());
    }

    /**
     * An explicit --dry-run is a dry run, and a refusal exits 1 as an error
     *
     * @return void
     */
    public function testRefusalIsAnError(): void
    {
        $queue  = new InMemoryIssueQueue();
        $tester = new CommandTester($this->command($queue));

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertSame(1, $tester->execute(['--limit' => 'x']));
        $this->assertStringContainsString('--limit must be', $tester->getDisplay());
        $this->assertSame([], $queue->enqueued());
    }

    /**
     * Build the command over the real service, a fake GitLab with two open issues and the given queue
     *
     * @param InMemoryIssueQueue $queue The queue
     *
     * @return TriageBackfillCommand
     */
    private function command(InMemoryIssueQueue $queue): TriageBackfillCommand
    {
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: true);
        $logger   = $this->createStub(Logger::class);
        $factory  = new Psr17Factory();
        $gitlab   = (new FakeIssueListGitlab())->withIssue('org/proj', 1)->withIssue('org/proj', 2);

        return new TriageBackfillCommand($logger, new TriageBackfillService($logger, new GitlabClientFactory($logger, $gitlab, $factory, $factory, new JsonService($logger), $settings), $queue, $queue, $settings, InMemoryProjects::service(['org/proj' => ProjectMode::Propose])));
    }
}
