<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixCaps;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Enum\EnqueueOutcome;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Kelpie\Service\Autofix\AutofixJobCoalescerService;
use PDOException;
use Psr\Log\LoggerInterface as Logger;
use ReflectionProperty;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\AutofixJobCoalescerService
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\AutofixJobCoalescerService
 */
final class AutofixJobCoalescerServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixJobCoalescerService::class);
    }

    /**
     * With nothing open the job is created
     *
     * @return void
     */
    public function testEnqueuesWhenNothingIsOpen(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('createJob');
        $writer->expects($this->never())->method('supersedeJob');

        $input = new AutofixJob('g/p', 5, 'new', 'note', 'd1');

        $this->assertSame(EnqueueOutcome::Enqueued, $this->coalescer(null, $writer)->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * The same head sha while a job is open coalesces
     *
     * @return void
     */
    public function testSameShaCoalesces(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->never())->method('createJob');

        $open  = new AutofixJobRecord(projectPath: 'g/p', mrIid: 5, headSha: 'same', source: 'note', id: 1, threadKey: 'd1', status: 'pending');
        $input = new AutofixJob('g/p', 5, 'same', 'note', 'd1');

        $this->assertSame(EnqueueOutcome::Coalesced, $this->coalescer($open, $writer)->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * A newer head sha supersedes a pending job
     *
     * @return void
     */
    public function testNewerShaSupersedesPending(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('supersedeJob')->with(1);
        $writer->expects($this->once())->method('createJob');

        $open  = new AutofixJobRecord(projectPath: 'g/p', mrIid: 5, headSha: 'old', source: 'note', id: 1, threadKey: 'd1', status: 'pending');
        $input = new AutofixJob('g/p', 5, 'new', 'note', 'd1');

        $this->assertSame(EnqueueOutcome::Enqueued, $this->coalescer($open, $writer)->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * A running job is never superseded
     *
     * @return void
     */
    public function testRunningJobIsLeftAlone(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->never())->method('supersedeJob');
        $writer->expects($this->never())->method('createJob');

        $open  = new AutofixJobRecord(projectPath: 'g/p', mrIid: 5, headSha: 'old', source: 'note', id: 1, threadKey: 'd1', status: 'running');
        $input = new AutofixJob('g/p', 5, 'new', 'note', 'd1');

        $this->assertSame(EnqueueOutcome::Coalesced, $this->coalescer($open, $writer)->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * The per-thread and per-day caps stop a job too
     *
     * @return void
     */
    public function testOtherCaps(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->never())->method('createJob');

        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('countAttemptsForThread')->willReturn(2);

        $input = new AutofixJob('g/p', 5, 'new', 'note', 'd1');
        $this->assertSame(EnqueueOutcome::CapExceeded, (new AutofixJobCoalescerService($this->createStub(Logger::class), $reader, $writer, new AutofixCaps()))->enqueue($input, new DateTimeImmutable()));

        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('countJobsSince')->willReturn(20);

        $this->assertSame(EnqueueOutcome::CapExceeded, (new AutofixJobCoalescerService($this->createStub(Logger::class), $reader, $writer, new AutofixCaps()))->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * A cap stops the job from being created
     *
     * @return void
     */
    public function testCapExceeded(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->never())->method('createJob');

        $input = new AutofixJob('g/p', 5, 'new', 'note', 'd1');

        $this->assertSame(EnqueueOutcome::CapExceeded, $this->coalescer(null, $writer, 3)->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * A fan-out job runs nothing, so the caps (here the commits per MR) do not bind it, and a review job is keyed by its head
     *
     * @return void
     */
    public function testFanOutJobsAreNotCapped(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('createJob')->with($this->callback(
            static function (AutofixJobRecord $job): bool {
                return $job->getThreadKey() === 'review-abc' && $job->getDiscussionId() === null;
            },
        ));

        $input = new AutofixJob('g/p', 0, 'abc', AutofixJob::REVIEW);

        $this->assertSame('review-abc', $this->coalescer(null, $writer, 99)->threadKey($input));
        $this->assertSame(EnqueueOutcome::Enqueued, $this->coalescer(null, $writer, 99)->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * Losing the unique-index race coalesces
     *
     * @return void
     */
    public function testIndexRaceCoalesces(): void
    {
        $exception = new PDOException('Duplicate entry');
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($exception, '23000');

        $writer = $this->createStub(AutofixJobWriter::class);
        $writer->method('createJob')->willThrowException($exception);

        $input = new AutofixJob('g/p', 5, 'new', 'note', 'd1');

        $this->assertSame(EnqueueOutcome::Coalesced, $this->coalescer(null, $writer)->enqueue($input, new DateTimeImmutable()));
    }

    /**
     * Build a coalescer around a reader that reports the given open job and counts
     *
     * @param ?AutofixJobRecord $open    The open job
     * @param AutofixJobWriter  $writer  The writer
     * @param int               $commits Fix commits on the MR
     *
     * @return AutofixJobCoalescerService The coalescer
     */
    private function coalescer(?AutofixJobRecord $open, AutofixJobWriter $writer, int $commits = 0): AutofixJobCoalescerService
    {
        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('getOpenJob')->willReturn($open);
        $reader->method('countFixCommitsForMr')->willReturn($commits);

        return new AutofixJobCoalescerService($this->createStub(Logger::class), $reader, $writer, new AutofixCaps());
    }
}
