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
     * Counting jobs since a moment formats the window start
     *
     * @return void
     */
    public function testCountJobsSince(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getColumn')->with($this->anything(), ['project_path' => 'g/p', 'since' => '2026-10-14 12:00:00'])->willReturn('4');

        $repo = new AutofixJobSqlRepository($this->createStub(Logger::class), $sql);

        $this->assertSame(4, $repo->countJobsSince('g/p', new DateTimeImmutable('2026-10-14 12:00:00')));
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
