<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Generator;
use Kelpie\Autofix\DataTransferObject\AutofixJob;
use Kelpie\DataTransferObject\AutofixCaps;
use Kelpie\Enum\EnqueueOutcome;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\AutofixJobService
 *
 * What must hold:
 * - A new job is enqueued and handed back pending
 * - A cap hit hands back no job
 * - An open job at another head, or one that is already running, is not handed back
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\AutofixJobService
 */
final class AutofixJobServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixJobService::class);
    }

    /**
     * An enqueued job is handed back pending
     *
     * @return void
     */
    public function testEnqueuedJobIsReturnedPending(): void
    {
        $sql = $this->createStub(SqlService::class);
        $sql->method('getColumn')->willReturn('0');
        $sql->method('query')->willReturn(1);
        $sql->method('getRows')->willReturnOnConsecutiveCalls($this->rows(null), $this->rows('pending', self::SHA));

        [$outcome, $job] = $this->service($sql)->pendingJob($this->input(), new DateTimeImmutable());

        $this->assertSame(EnqueueOutcome::Enqueued, $outcome);
        $this->assertNotNull($job);
        $this->assertSame(7, $job->getId());
    }

    /**
     * A cap hit hands back nothing
     *
     * @return void
     */
    public function testCapExceeded(): void
    {
        $sql = $this->createStub(SqlService::class);
        $sql->method('getColumn')->willReturn('99');
        $sql->method('getRows')->willReturnCallback(function (): Generator {
            return $this->rows(null);
        });

        [$outcome, $job] = $this->service($sql)->pendingJob($this->input(), new DateTimeImmutable());

        $this->assertSame(EnqueueOutcome::CapExceeded, $outcome);
        $this->assertNull($job);
    }

    /**
     * A job that is already running is coalesced into and not handed out again
     *
     * @return void
     */
    public function testRunningJobIsNotHandedOut(): void
    {
        $sql = $this->createStub(SqlService::class);
        $sql->method('getColumn')->willReturn('0');
        $sql->method('getRows')->willReturnCallback(function (): Generator {
            return $this->rows('running', self::SHA);
        });

        [$outcome, $job] = $this->service($sql)->pendingJob($this->input(), new DateTimeImmutable());

        $this->assertSame(EnqueueOutcome::Coalesced, $outcome);
        $this->assertNull($job);
    }

    /**
     * The reader and writer are the repository
     *
     * @return void
     */
    public function testHandsOutReaderAndWriter(): void
    {
        $service = $this->service($this->createStub(SqlService::class));

        $this->assertSame($service->reader(), $service->writer());
    }

    /**
     * @param SqlService $sql The SQL service
     *
     * @return AutofixJobService
     */
    private function service(SqlService $sql): AutofixJobService
    {
        $logger = $this->createStub(Logger::class);

        return new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql), new AutofixCaps());
    }

    /**
     * @return AutofixJob
     */
    private function input(): AutofixJob
    {
        return new AutofixJob('g/p', 5, self::SHA, 'note', 'd1');
    }

    /**
     * Zero or one job row
     *
     * @param ?string $status The status, null for no row
     * @param string  $sha    The head sha
     *
     * @return Generator<int, array<string, ?string>>
     */
    private function rows(?string $status, string $sha = ''): Generator
    {
        $row = [
            'after_autofix' => '0',
            'attempt'       => '0',
            'commit_sha'    => null,
            'date_created'  => '2026-10-15 12:00:00',
            'discussion_id' => 'd1',
            'head_sha'      => $sha,
            'id'            => '7',
            'mr_iid'        => '5',
            'project_path'  => 'g/p',
            'source'        => 'note',
            'status'        => $status,
            'thread_key'    => 'd1',
            'triage_class'  => null,
        ];

        if ($status !== null) {
            yield $row;
        }
    }
}
