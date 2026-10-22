<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use ArrayObject;
use DateTimeImmutable;
use Generator;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Policy\EventFilter;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\EnqueueService;
use Kelpie\Service\Autofix\MrJobWorkerService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Autofix\SweepService;
use Kelpie\Tests\Console\Command\CommandServices;
use Kelpie\Tests\GitLab\FakeGitlab;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\MrJobWorkerService
 *
 * What must hold:
 * - Off, nothing is claimed; with nothing due, nothing is done
 * - A job is leased before it runs, and a job that finishes is marked done
 * - A review job (no MR, no thread) fans out into the thread jobs of the MR at its head; one for a moved head does nothing
 * - A thread job for a closed MR or a resolved thread is done, not retried
 * - A failure is retried after one minute, then five; the third is final
 * - A job left by a worker that died on its last attempt is ended, not run again
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\MrJobWorkerService
 */
final class MrJobWorkerServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FakeGitlab $gitlab;

    /**
     * The SQL statements the sweep's repository ran
     *
     * @var ArrayObject<int, string>
     */
    private ArrayObject $queries;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MrJobWorkerService::class);
    }

    /**
     * Autofix off: nothing is claimed
     *
     * @return void
     */
    public function testDisabledClaimsNothing(): void
    {
        $reader = $this->createMock(AutofixJobReader::class);
        $reader->expects($this->never())->method('getDueJob');

        $this->assertFalse($this->service($reader, $this->createStub(AutofixJobWriter::class))->step(false, ['ubixsys/ubixkelpie'], new DateTimeImmutable()));
    }

    /**
     * Nothing due: the step says so and the caller may poll
     *
     * @return void
     */
    public function testNothingDue(): void
    {
        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('getDueJob')->willReturn(null);
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->never())->method('lockJob');

        $this->assertFalse($this->service($reader, $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable()));
    }

    /**
     * Losing the lease race (the read-back finds another worker's lease) takes no job
     *
     * @return void
     */
    public function testLostLeaseTakesNothing(): void
    {
        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('getDueJob')->willReturn($this->job(null, 0));
        $reader->method('getJobLockedBy')->willReturn(null);
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('lockJob')->with(7, 0, 'w1', $this->anything(), MrJobWorkerService::LOCK_SECONDS);
        $writer->expects($this->never())->method('markDone');

        $this->assertFalse($this->service($reader, $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable()));
    }

    /**
     * A finished review job fans out: the MR at its head gets a job for each unresolved thread
     *
     * @return void
     */
    public function testReviewJobFansOutIntoThreadJobs(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);

        $step = $this->service($this->readerOf($this->job(null, 1, 0, self::HEAD, 'review')), $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());

        $this->assertTrue($step);
        $this->assertStringContainsString('INSERT INTO ubixkelpie.autofix_jobs', implode("\n", $this->queries->getArrayCopy()));
    }

    /**
     * A review job whose head is no longer an MR's head queues nothing: that push raised its own event
     *
     * @return void
     */
    public function testReviewJobForAMovedHeadQueuesNothing(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);

        $this->service($this->readerOf($this->job(null, 1, 0, str_repeat('b', 40), 'review')), $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());

        $this->assertCount(0, $this->queries);
    }

    /**
     * A thread job for an MR that is no longer open is done, not retried
     *
     * @return void
     */
    public function testClosedMrIsDone(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);
        $writer->expects($this->never())->method('markRetry');

        $this->service($this->readerOf($this->job('d1abc123', 1, 99)), $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());
    }

    /**
     * A thread job whose thread is resolved is done
     *
     * @return void
     */
    public function testResolvedThreadIsDone(): void
    {
        $this->gitlab->note('d1abc123', 'Another thread.');
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);

        $this->service($this->readerOf($this->job('gone0000', 1)), $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());
    }

    /**
     * A thread job the runner ends itself (the head moved, so it supersedes the job) is not marked again
     *
     * @return void
     */
    public function testRunnerEndedJobIsDone(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);
        $writer->expects($this->never())->method('markRetry');

        $this->service($this->readerOf($this->job('d1abc123', 1, 3, str_repeat('b', 40)), 'superseded'), $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());
    }

    /**
     * A job the runner left running is waiting on something: it is retried in a minute
     *
     * @return void
     */
    public function testJobLeftRunningIsRetried(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $now    = new DateTimeImmutable('2026-10-21 12:00:00');
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markRetry')->with(7, $now->modify('+60 seconds'), $this->stringContains('not run yet'));
        $writer->expects($this->never())->method('markDone');

        $this->service($this->readerOf($this->job('d1abc123', 1, 3, str_repeat('b', 40)), 'running'), $writer)->step(true, ['ubixsys/ubixkelpie'], $now);
    }

    /**
     * A throw is retried after one minute, then five, and the third is final
     *
     * @return void
     */
    public function testRetryBackoffThenGiveUp(): void
    {
        $now = new DateTimeImmutable('2026-10-21 12:00:00');

        foreach ([[1, 60], [2, 300]] as [$attempt, $seconds]) {
            $writer = $this->createMock(AutofixJobWriter::class);
            $writer->expects($this->once())->method('markRetry')->with(7, $now->modify('+' . $seconds . ' seconds'), 'boom');
            $this->service($this->throwingReader($attempt), $writer)->step(true, ['ubixsys/ubixkelpie'], $now);
        }

        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markFailed')->with(7, 'boom');
        $writer->expects($this->never())->method('markRetry');
        $this->service($this->throwingReader(MrJobWorkerService::MAX_ATTEMPTS), $writer)->step(true, ['ubixsys/ubixkelpie'], $now);
    }

    /**
     * A job past its last attempt was left by a worker that died: it is ended without running
     *
     * @return void
     */
    public function testJobOfADeadWorkerIsEnded(): void
    {
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markFailed')->with(7, $this->stringContains('died'));

        $step = $this->service($this->readerOf($this->job('d1abc123', MrJobWorkerService::MAX_ATTEMPTS + 1)), $writer)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());

        $this->assertTrue($step);
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * A claim that throws is logged and takes no job
     *
     * @return void
     */
    public function testClaimFailureTakesNoJob(): void
    {
        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('getDueJob')->willThrowException(new RuntimeException('db down'));

        $this->assertFalse($this->service($reader, $this->createStub(AutofixJobWriter::class))->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable()));
    }

    /**
     * A thumbs-down job goes to the reaction service, not the fixer: the proposal is declined and the thread is told
     *
     * @return void
     */
    public function testADeclineJobDeclinesTheProposal(): void
    {
        $this->gitlab->note('d1abc123', 'Kelpie proposes this change.', id: 105, author: 'kelpie-bot');
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);
        $writer->expects($this->never())->method('markRetry');

        $this->service($this->readerOf($this->job('d1abc123', 1, 3, self::HEAD, 'decline')), $writer, true)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());

        $this->assertContains('UPDATE ubixkelpie.thread_outcomes SET outcome = :outcome
                WHERE project_path = :project_path AND mr_iid = :mr_iid AND discussion_id = :discussion_id', $this->queries->getArrayCopy());
        $notes = $this->gitlab->notes()['d1abc123'];
        $this->assertStringContainsString('class=declined', $notes[count($notes) - 1]['body']);
    }

    /**
     * A thumbs-up job whose head has moved gets the stale reply, with no checkout and no retry
     *
     * @return void
     */
    public function testAnApplyJobForAMovedHeadIsToldStale(): void
    {
        $this->gitlab->note('d1abc123', 'Kelpie proposes this change.', id: 105, author: 'kelpie-bot');
        $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);
        $writer->expects($this->never())->method('markRetry');

        $this->service($this->readerOf($this->job('d1abc123', 1, 3, self::HEAD, 'apply')), $writer, true)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());

        $notes = $this->gitlab->notes()['d1abc123'];
        $this->assertStringContainsString('class=stale', $notes[count($notes) - 1]['body']);
    }

    /**
     * A thumbs-up job in a project that is only in propose mode is handled without a push: the reply says so, no retry
     *
     * @return void
     */
    public function testAnApplyJobInProposeModeIsNotPushed(): void
    {
        $this->gitlab->note('d1abc123', 'Kelpie proposes this change.', id: 105, author: 'kelpie-bot');
        $writer = $this->createMock(AutofixJobWriter::class);
        $writer->expects($this->once())->method('markDone')->with(7);
        $writer->expects($this->never())->method('markRetry');

        $this->service($this->readerOf($this->job('d1abc123', 1, 3, self::HEAD, 'apply')), $writer, true, ProjectMode::Propose)->step(true, ['ubixsys/ubixkelpie'], new DateTimeImmutable());

        $notes = $this->gitlab->notes()['d1abc123'];
        $this->assertStringContainsString('class=not-pushed', $notes[count($notes) - 1]['body']);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab  = new FakeGitlab();
        $this->queries = new ArrayObject();
    }

    /**
     * A reader whose job runs into an exception at the attempt given, after the runner returns
     *
     * @param int $attempt The attempt the lease read back
     *
     * @return AutofixJobReader
     */
    private function throwingReader(int $attempt): AutofixJobReader
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $job    = $this->job('d1abc123', $attempt, 3, str_repeat('b', 40));
        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('getDueJob')->willReturn($job);
        $reader->method('getJobLockedBy')->willReturn($job);
        $reader->method('getJobById')->willThrowException(new RuntimeException('boom'));

        return $reader;
    }

    /**
     * A reader that offers the job and, once leased, hands it back; the runner's later read of it shows the status given
     *
     * @param AutofixJobRecord $job    The job
     * @param string           $status What the job reads as after the runner
     *
     * @return AutofixJobReader
     */
    private function readerOf(AutofixJobRecord $job, string $status = 'done'): AutofixJobReader
    {
        $reader = $this->createStub(AutofixJobReader::class);
        $reader->method('getDueJob')->willReturn($job);
        $reader->method('getJobLockedBy')->willReturn($job);
        $reader->method('getJobById')->willReturn($this->job($job->getDiscussionId(), 1, $job->getMrIid() ?? 0, $job->getHeadSha() ?? '', 'note', $status));

        return $reader;
    }

    /**
     * @param ?string $discussionId The thread, null for a fan-out job
     * @param int     $attempt      The attempt count
     * @param int     $mrIid        The MR
     * @param string  $headSha      The head sha
     * @param string  $source       The source
     * @param string  $status       The status
     *
     * @return AutofixJobRecord
     */
    private function job(?string $discussionId, int $attempt, int $mrIid = 3, string $headSha = self::HEAD, string $source = 'note', string $status = 'running'): AutofixJobRecord
    {
        return new AutofixJobRecord(
            projectPath:  'ubixsys/ubixkelpie',
            mrIid:        $mrIid,
            headSha:      $headSha,
            source:       $source,
            id:           7,
            discussionId: $discussionId,
            threadKey:    $discussionId ?? '',
            afterAutofix: false,
            status:       $status,
            attempt:      $attempt,
        );
    }

    /**
     * Build the worker over the fake GitLab and a stubbed SQL service
     *
     * @param AutofixJobReader $reader           The job reader
     * @param AutofixJobWriter $writer           The job writer
     * @param bool             $proposalOnRecord Whether thread d1abc123 has an open proposal on record, made at HEAD
     * @param ProjectMode      $mode             The mode of ubixsys/ubixkelpie; push by default
     *
     * @return MrJobWorkerService
     */
    private function service(AutofixJobReader $reader, AutofixJobWriter $writer, bool $proposalOnRecord = false, ProjectMode $mode = ProjectMode::Push): MrJobWorkerService
    {
        $logger   = $this->createStub(Logger::class);
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: true, openRouterKey: 'key-not-real');
        $gitlab   = new GitlabClientFactory($logger, $this->gitlab, $factory, $factory, $json, $settings);
        $queries  = $this->queries;
        $sql      = $this->createStub(SqlService::class);
        $sql->method('query')->willReturnCallback(static function (string $statement) use ($queries): int {
            $queries[] = $statement;

            return 1;
        });
        $sql->method('lastInsertId')->willReturn('7');
        $sql->method('getColumn')->willReturn('0');
        $sql->method('getRows')->willReturnCallback(static function (string $statement) use ($proposalOnRecord): Generator {
            if ($proposalOnRecord && str_contains($statement, 'thread_outcomes')) {
                yield [
                    'completion_tokens' => '0',
                    'cost_usd'          => null,
                    'diff'              => '',
                    'discussion_id'     => 'd1abc123',
                    'edits_json'        => '[{"file":"a.txt","new":"b","old":"a"}]',
                    'head_sha'          => self::HEAD,
                    'mode'              => 'propose',
                    'mr_iid'            => '3',
                    'outcome'           => 'proposed',
                    'project_path'      => 'ubixsys/ubixkelpie',
                    'prompt_tokens'     => '0',
                    'proposal_note_id'  => '105',
                    'propose_model'     => '',
                    'reason'            => 'Adds a line.',
                    'review_family'     => '',
                    'triage_model'      => '',
                    'verdict'           => 'fix',
                ];
            }
        });

        $jobs  = new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql));
        $sweep = new SweepService(
            $logger,
            true,
            ['ubixsys/ubixkelpie'],
            5,
            new EnqueueService($logger, new EventFilter($logger), $jobs),
            $gitlab->lister(),
            $gitlab->reader(),
            $gitlab->writer(),
            $gitlab->jobLogReader(),
            $jobs,
            new ReplyService($logger, $gitlab->writer()),
        );

        return new MrJobWorkerService(
            $logger,
            $reader,
            $writer,
            $gitlab->lister(),
            $gitlab->reader(),
            $sweep,
            (new CommandServices($logger, $this->gitlab, $sql, $settings, ['ubixsys/ubixkelpie' => $mode]))->runners(),
            (new CommandServices($logger, $this->gitlab, $sql, $settings, ['ubixsys/ubixkelpie' => $mode]))->reactions(),
            'w1',
        );
    }
}
