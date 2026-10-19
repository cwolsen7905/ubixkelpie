<?php

declare(strict_types=1);

namespace Kelpie\Tests\Repository\IssueTriageJob;

use DateTimeImmutable;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobSqlRepository;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Repository\IssueTriageJob\IssueTriageJobSqlRepository
 *
 * What must hold:
 * - Enqueue is an INSERT IGNORE keyed by project, issue and source, with a per-issue thread key
 * - A job is due only for enrolled projects, with attempts left, and the lock is one conditional UPDATE on the attempt read
 * - Nothing is read without enrolled projects
 * - Settling touches a running job only and clears its lock
 *
 * @coversDefaultClass \Kelpie\Repository\IssueTriageJob\IssueTriageJobSqlRepository
 */
final class IssueTriageJobSqlRepositoryTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueTriageJobSqlRepository::class);
    }

    /**
     * Enqueue inserts the issue job with its own thread key
     *
     * @return void
     */
    public function testEnqueue(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')
            ->with($this->stringContains('INSERT IGNORE INTO ubixkelpie.autofix_jobs'), ['issue_iid' => 7, 'project_path' => 'g/p', 'source' => 'issue_triage', 'thread_key' => 'issue-7'])
            ->willReturn(1);

        (new IssueTriageJobSqlRepository($this->createStub(Logger::class), $sql))->enqueue('g/p', 7);
    }

    /**
     * The due job is read for the enrolled projects
     *
     * @return void
     */
    public function testGetDueJob(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getRow')->with(
            $this->logicalAnd($this->stringContains('IN (:project_0, :project_1)'), $this->stringContains('attempt < :max_attempts'), $this->stringContains('next_attempt_at <= :due_now')),
            ['source' => 'issue_triage', 'project_0' => 'g/a', 'project_1' => 'g/b', 'due_now' => '2026-10-19 12:00:00', 'lock_now' => '2026-10-19 12:00:00', 'max_attempts' => 3],
        )->willReturn(['attempt' => '1', 'id' => '9', 'issue_iid' => '7', 'project_path' => 'g/b']);

        $job = (new IssueTriageJobSqlRepository($this->createStub(Logger::class), $sql))->getDueJob(['g/a', 'g/b'], new DateTimeImmutable('2026-10-19 12:00:00 UTC'), 3);

        $this->assertNotNull($job);
        $this->assertSame([9, 'g/b', 7, 1], [$job->id, $job->projectPath, $job->issueIid, $job->attempt]);
    }

    /**
     * The job of one issue is found by project and issue, in any state
     *
     * @return void
     */
    public function testGetJobForIssue(): void
    {
        $found = $this->createMock(SqlService::class);
        $found->expects($this->once())->method('getRow')->with(
            $this->logicalAnd($this->stringContains('project_path = :project_path AND issue_iid = :issue_iid'), $this->logicalNot($this->stringContains('status'))),
            ['source' => 'issue_triage', 'project_path' => 'g/p', 'issue_iid' => 7],
        )->willReturn(['attempt' => '3', 'id' => '9', 'issue_iid' => '7', 'project_path' => 'g/p']);
        $none = $this->createStub(SqlService::class);
        $none->method('getRow')->willReturn(false);

        $logger = $this->createStub(Logger::class);
        $job    = (new IssueTriageJobSqlRepository($logger, $found))->getJobForIssue('g/p', 7);

        $this->assertNotNull($job);
        $this->assertSame(9, $job->id);
        $this->assertNull((new IssueTriageJobSqlRepository($logger, $none))->getJobForIssue('g/p', 8));
    }

    /**
     * An empty queue is null, and an empty project list is not even queried
     *
     * @return void
     */
    public function testNothingDue(): void
    {
        $now    = new DateTimeImmutable('2026-10-19 12:00:00 UTC');
        $logger = $this->createStub(Logger::class);

        $empty = $this->createStub(SqlService::class);
        $empty->method('getRow')->willReturn(false);
        $never = $this->createMock(SqlService::class);
        $never->expects($this->never())->method('getRow');

        $this->assertNull((new IssueTriageJobSqlRepository($logger, $empty))->getDueJob(['g/a'], $now, 3));
        $this->assertNull((new IssueTriageJobSqlRepository($logger, $never))->getDueJob([], $now, 3));
    }

    /**
     * The lock is one UPDATE on the attempt that was read, and the lease is in UTC
     *
     * @return void
     */
    public function testLockJob(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')->with(
            $this->logicalAnd($this->stringContains('WHERE id = :id AND attempt = :attempt'), $this->stringContains('attempt = attempt + 1')),
            ['attempt' => 1, 'id' => 9, 'lock_now' => '2026-10-19 12:00:00', 'locked_by' => 'w1', 'locked_until' => '2026-10-19 12:10:00'],
        )->willReturn(1);

        (new IssueTriageJobSqlRepository($this->createStub(Logger::class), $sql))->lockJob(9, 1, 'w1', new DateTimeImmutable('2026-10-19 14:00:00 +02:00'), 600);
    }

    /**
     * Only the worker named in the lock gets the job back, and a lost race is null
     *
     * @return void
     */
    public function testGetJobLockedBy(): void
    {
        $won = $this->createMock(SqlService::class);
        $won->expects($this->once())->method('getRow')->with($this->stringContains('locked_by = :locked_by'), ['source' => 'issue_triage', 'id' => 9, 'locked_by' => 'w1'])
            ->willReturn(['attempt' => '2', 'id' => '9', 'issue_iid' => '7', 'project_path' => 'g/p']);
        $lost = $this->createStub(SqlService::class);
        $lost->method('getRow')->willReturn(false);

        $logger = $this->createStub(Logger::class);
        $job    = (new IssueTriageJobSqlRepository($logger, $won))->getJobLockedBy(9, 'w1');

        $this->assertNotNull($job);
        $this->assertSame(2, $job->attempt);
        $this->assertNull((new IssueTriageJobSqlRepository($logger, $lost))->getJobLockedBy(9, 'w2'));
    }

    /**
     * Done, retry and failed only touch a running job and clear its lock
     *
     * @return void
     */
    public function testSettling(): void
    {
        $logger = $this->createStub(Logger::class);
        $clears = $this->logicalAnd($this->stringContains("status = 'running'"), $this->stringContains('locked_until = NULL'), $this->stringContains('locked_by = NULL'));

        $done = $this->createMock(SqlService::class);
        $done->expects($this->once())->method('query')->with($this->logicalAnd($this->stringContains("status = 'done'"), $clears), ['id' => 9, 'triage_class' => 'fix'])->willReturn(1);
        (new IssueTriageJobSqlRepository($logger, $done))->markDone(9, 'fix');

        $retry = $this->createMock(SqlService::class);
        $retry->expects($this->once())->method('query')->with(
            $this->logicalAnd($this->stringContains("status = 'pending'"), $clears),
            ['id' => 9, 'last_error' => str_repeat('e', 255), 'next_attempt_at' => '2026-10-19 12:05:00'],
        )->willReturn(1);
        (new IssueTriageJobSqlRepository($logger, $retry))->markRetry(9, new DateTimeImmutable('2026-10-19 12:05:00 UTC'), str_repeat('e', 400));

        $failed = $this->createMock(SqlService::class);
        $failed->expects($this->once())->method('query')->with($this->logicalAnd($this->stringContains("status = 'failed'"), $clears), ['id' => 9, 'last_error' => 'gave up'])->willReturn(1);
        (new IssueTriageJobSqlRepository($logger, $failed))->markFailed(9, 'gave up');
    }
}
