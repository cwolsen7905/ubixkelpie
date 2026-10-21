<?php

declare(strict_types=1);

namespace Kelpie\Tests\Repository\AutofixJob;

use DateTimeImmutable;
use Generator;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Repository\AutofixJob\AutofixJobSqlRepository
 *
 * @coversDefaultClass \Kelpie\Repository\AutofixJob\AutofixJobSqlRepository
 */
final class AutofixJobSqlRepositoryTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixJobSqlRepository::class);
    }

    /**
     * Creating a job inserts it pending and sets the new id
     *
     * @return void
     */
    public function testCreateJobSetsId(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')->with($this->stringContains('INSERT INTO ubixkelpie.autofix_jobs'), $this->callback(
            static function (array $p): bool {
                return $p['thread_key'] === 'd1' && $p['after_autofix'] === 0;
            },
        ))->willReturn(1);
        $sql->method('lastInsertId')->willReturn('42');

        $job = new AutofixJobRecord(projectPath: 'g/p', mrIid: 5, headSha: 'abc', source: 'note', discussionId: 'd1', threadKey: 'd1');
        (new AutofixJobSqlRepository($this->createStub(Logger::class), $sql))->createJob($job);

        $this->assertSame(42, $job->getId());
    }

    /**
     * The open job is read by the generated open marker
     *
     * @return void
     */
    public function testGetOpenJobHydrates(): void
    {
        $row = [
            'after_autofix' => '0',
            'attempt'       => '0',
            'commit_sha'    => null,
            'date_created'  => '2026-10-15 12:00:00',
            'discussion_id' => null,
            'head_sha'      => 'abc',
            'id'            => '7',
            'mr_iid'        => '5',
            'project_path'  => 'g/p',
            'source'        => 'pipeline',
            'status'        => 'pending',
            'thread_key'    => '',
            'triage_class'  => null,
        ];

        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getRows')->with($this->stringContains('open_marker = 1'))->willReturn($this->rows($row));

        $job = (new AutofixJobSqlRepository($this->createStub(Logger::class), $sql))->getOpenJob('g/p', 5, '');

        $this->assertInstanceOf(AutofixJobRecord::class, $job);
        $this->assertSame(7, $job->getId());
        $this->assertNull($job->getDiscussionId());
    }

    /**
     * Counting jobs since a moment formats the window start and leaves issue triage jobs out of the MR caps
     *
     * @return void
     */
    public function testCountJobsSince(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getColumn')->with($this->stringContains('source <> :issue_source'), ['issue_source' => 'issue_triage', 'project_path' => 'g/p', 'since' => '2026-10-14 12:00:00'])->willReturn('4');

        $repo = new AutofixJobSqlRepository($this->createStub(Logger::class), $sql);

        $this->assertSame(4, $repo->countJobsSince('g/p', new DateTimeImmutable('2026-10-14 12:00:00')));
    }

    /**
     * Counting jobs leaves out the fan-out rows, which run nothing
     *
     * @return void
     */
    public function testCountJobsSinceIgnoresFanOutRows(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getColumn')->with($this->stringContains('discussion_id IS NOT NULL'))->willReturn('0');

        (new AutofixJobSqlRepository($this->createStub(Logger::class), $sql))->countJobsSince('g/p', new DateTimeImmutable());
    }

    /**
     * The due job is a pending or lease-expired MR job of an enrolled project, due and under the attempt limit
     *
     * @return void
     */
    public function testGetDueJob(): void
    {
        $row = [
            'after_autofix' => '0', 'attempt'       => '1', 'commit_sha'    => null, 'date_created'  => '2026-10-15 12:00:00',
            'discussion_id' => 'd1', 'head_sha'      => 'abc', 'id'            => '7', 'mr_iid'        => '5', 'project_path'  => 'g/p',
            'source'        => 'note', 'status'        => 'pending', 'thread_key'    => 'd1', 'triage_class'  => null,
        ];
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getRow')->with(
            $this->logicalAnd($this->stringContains('source <> :issue_source'), $this->stringContains('locked_until < :lock_now'), $this->stringContains('project_path IN (:project_0, :project_1)')),
            $this->callback(
                static function (array $p): bool {
                    return $p['due_now'] === '2026-10-21 12:00:00' && $p['max_attempts'] === 4 && $p['project_1'] === 'g/q';
                },
            ),
        )->willReturn($row);

        $repo = new AutofixJobSqlRepository($this->createStub(Logger::class), $sql);
        $job  = $repo->getDueJob(['g/p', 'g/q'], new DateTimeImmutable('2026-10-21 12:00:00'), 4);

        $this->assertSame(7, $job?->getId());
        $this->assertNull($repo->getDueJob([], new DateTimeImmutable(), 4));
    }

    /**
     * Taking the lease is one conditional UPDATE
     *
     * @return void
     */
    public function testLockJobIsConditional(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')->with(
            $this->logicalAnd($this->stringContains('WHERE id = :id AND attempt = :attempt'), $this->stringContains('locked_until < :lock_now')),
            ['attempt' => 1, 'id' => 7, 'lock_now' => '2026-10-21 12:00:00', 'locked_by' => 'w1', 'locked_until' => '2026-10-21 12:30:00'],
        )->willReturn(1);

        (new AutofixJobSqlRepository($this->createStub(Logger::class), $sql))->lockJob(7, 1, 'w1', new DateTimeImmutable('2026-10-21 12:00:00'), 1800);
    }

    /**
     * Retry puts the job back pending with its next time and error; failed and done only touch a running job
     *
     * @return void
     */
    public function testRetryDoneAndFailed(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->exactly(3))->method('query')->willReturnCallback(function (string $statement, array $parameters): int {
            $this->assertStringContainsString("AND status = 'running'", $statement);
            if (str_contains($statement, "status = 'pending'")) {
                $this->assertSame(['id' => 7, 'last_error' => 'boom', 'next_attempt_at' => '2026-10-21 12:01:00'], $parameters);
            }

            return 1;
        });

        $repo = new AutofixJobSqlRepository($this->createStub(Logger::class), $sql);
        $repo->markRetry(7, new DateTimeImmutable('2026-10-21 12:01:00'), 'boom');
        $repo->markDone(7);
        $repo->markFailed(7, 'boom');
    }

    /**
     * Yield one result row
     *
     * @param array<string, bool|int|float|string|null> $row The row
     *
     * @return Generator<int, array<string, bool|int|float|string|null>> The rows
     */
    private function rows(array $row): Generator
    {
        yield $row;
    }
}
