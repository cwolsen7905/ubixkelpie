<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use DateTimeZone;
use DI\ContainerBuilder;
use Generator;
use Kelpie\DataTransferObject\AutofixCaps;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Enum\EnqueueOutcome;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\CapNoticeService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\AutofixJobService
 *
 * What must hold:
 * - A new job is enqueued and handed back pending
 * - A hard-cap hit hands back no job; passing a soft cap still enqueues
 * - A hard daily-cap refusal tells the MR once; another cap, or a count under the cap, says nothing
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
     * A hard-cap hit hands back nothing
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

        [$outcome, $job] = $this->service($sql, new AutofixCaps(commitsPerMrHard: 3))->pendingJob($this->input(), new DateTimeImmutable());

        $this->assertSame(EnqueueOutcome::CapExceeded, $outcome);
        $this->assertNull($job);
    }

    /**
     * A refusal for the MR's own hard cap leaves one note on that MR, naming the MR cap, and a second refusal leaves no second note
     *
     * @return void
     */
    public function testMrCapNotesTheMrOnce(): void
    {
        $gitlab  = new FakeGitlab();
        $service = $this->countingService(['project' => 50, 'mr3' => 30], $gitlab, new AutofixCaps(jobsPerMrHard: 30));
        $input   = new AutofixJob('g/p', 3, self::SHA, 'note', 'd1');
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        [$first]  = $service->pendingJob($input, $now);
        [$second] = $service->pendingJob($input, $now);

        $notes = array_merge(...array_values($gitlab->notes()));
        $this->assertSame(EnqueueOutcome::CapExceeded, $first);
        $this->assertSame(EnqueueOutcome::CapExceeded, $second);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('daily job cap for this MR (30 of 30 in 24 h)', $notes[0]['body']);
        $this->assertStringContainsString('around 08:30 UTC', $notes[0]['body']);
    }

    /**
     * The project hard ceiling stops every MR in it, and the note names the ceiling
     *
     * @return void
     */
    public function testProjectCeilingStopsEveryMr(): void
    {
        $gitlab  = new FakeGitlab();
        $service = $this->countingService(['project' => 200, 'mr3' => 1, 'mr4' => 0], $gitlab, new AutofixCaps(jobsPerProjectHard: 200));
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        [$three] = $service->pendingJob(new AutofixJob('g/p', 3, self::SHA, 'note', 'd1'), $now);
        [$four]  = $service->pendingJob(new AutofixJob('g/p', 4, self::SHA, 'note', 'd1'), $now);

        $this->assertSame(EnqueueOutcome::CapExceeded, $three);
        $this->assertSame(EnqueueOutcome::CapExceeded, $four);
        $notes = array_merge(...array_values($gitlab->notes()));
        $this->assertStringContainsString('project-wide daily job ceiling (200 of 200 in 24 h, across all MRs)', $notes[0]['body']);
    }

    /**
     * One MR at its hard cap does not stop another MR in the same project
     *
     * @return void
     */
    public function testAnotherMrStillRunsWhenOneIsCapped(): void
    {
        $gitlab  = new FakeGitlab();
        $service = $this->countingService(['project' => 31, 'mr3' => 30, 'mr4' => 0], $gitlab, new AutofixCaps(jobsPerMrHard: 30));
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        [$capped]  = $service->pendingJob(new AutofixJob('g/p', 3, self::SHA, 'note', 'd1'), $now);
        [$running] = $service->pendingJob(new AutofixJob('g/p', 4, self::SHA, 'note', 'd1'), $now);

        $this->assertSame(EnqueueOutcome::CapExceeded, $capped);
        $this->assertSame(EnqueueOutcome::Enqueued, $running);
    }

    /**
     * With nothing set the defaults apply: the soft caps (30 per MR, 200 per project) warn but do not stop, and with no hard limit the work keeps running
     *
     * @return void
     */
    public function testDefaultsApplyWhenUnset(): void
    {
        putenv('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY');
        putenv('KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->assertSame(EnqueueOutcome::Enqueued, $this->countingService(['project' => 199, 'mr3' => 29])->pendingJob(new AutofixJob('g/p', 3, self::SHA, 'note', 'd1'), $now)[0]);
        $this->assertSame(EnqueueOutcome::Enqueued, $this->countingService(['project' => 50, 'mr3' => 30])->pendingJob(new AutofixJob('g/p', 3, self::SHA, 'note', 'd1'), $now)[0]);
        $this->assertSame(EnqueueOutcome::Enqueued, $this->countingService(['project' => 200, 'mr3' => 1])->pendingJob(new AutofixJob('g/p', 3, self::SHA, 'note', 'd1'), $now)[0]);
    }

    /**
     * The settings change the caps
     *
     * @return void
     */
    public function testSettingsOverrideTheCaps(): void
    {
        putenv('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY=5');
        putenv('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY_HARD=5');
        putenv('KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY=500');

        try {
            $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $refused = $this->countingService(['project' => 100, 'mr3' => 5])->pendingJob(new AutofixJob('g/p', 3, self::SHA, 'note', 'd1'), $now)[0];
            $allowed = $this->countingService(['project' => 300, 'mr3' => 4])->pendingJob(new AutofixJob('g/p', 3, self::SHA, 'note', 'd1'), $now)[0];
        } finally {
            putenv('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY');
            putenv('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY_HARD');
            putenv('KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY');
        }

        $this->assertSame(EnqueueOutcome::CapExceeded, $refused);
        $this->assertSame(EnqueueOutcome::Enqueued, $allowed);
    }

    /**
     * A refusal for another cap, with the day's count under its cap, says nothing
     *
     * @return void
     */
    public function testOtherCapSaysNothing(): void
    {
        $gitlab = new FakeGitlab();
        $logger = $this->createStub(Logger::class);
        $sql    = $this->createStub(SqlService::class);
        $sql->method('getColumn')->willReturn('99');
        $sql->method('getRows')->willReturnCallback(function (): Generator {
            return $this->rows(null);
        });
        $service = new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql), new AutofixCaps(commitsPerMrHard: 3), new CapNoticeService($logger, $gitlab->writer($logger)));

        [$outcome] = $service->pendingJob($this->input(), new DateTimeImmutable());

        $this->assertSame(EnqueueOutcome::CapExceeded, $outcome);
        $this->assertSame([], $gitlab->requests());
    }

    /**
     * A refusal by the commit cap says nothing, even when the day's count is also at its cap
     *
     * @return void
     */
    public function testCommitCapSaysNothingEvenWhenTheDayIsFull(): void
    {
        $gitlab = new FakeGitlab();
        $logger = $this->createStub(Logger::class);
        $sql    = $this->createStub(SqlService::class);
        $sql->method('getColumn')->willReturn('100');
        $sql->method('getRows')->willReturnCallback(function (): Generator {
            return $this->rows(null);
        });
        $service = new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql), new AutofixCaps(commitsPerMrHard: 3), new CapNoticeService($logger, $gitlab->writer($logger)));

        [$outcome] = $service->pendingJob($this->input(), new DateTimeImmutable());

        $this->assertSame(EnqueueOutcome::CapExceeded, $outcome);
        $this->assertSame([], $gitlab->requests());
    }

    /**
     * The service built by the container reads the caps from the environment, not the DTO defaults
     *
     * @return void
     */
    public function testContainerBuiltServiceReadsTheEnvironment(): void
    {
        $logger = $this->createStub(Logger::class);
        $sql    = $this->createStub(SqlService::class);
        $sql->method('getColumn')->willReturn('2');
        $sql->method('query')->willReturn(1);
        $sql->method('getRows')->willReturnOnConsecutiveCalls($this->rows(null), $this->rows('pending', self::SHA));
        $container = (new ContainerBuilder())->addDefinitions([Logger::class => $logger, SqlService::class => $sql])->build();

        // Two attempts on the thread break the default cap of 2; the override allows them
        putenv('KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD=5');

        try {
            $service = $container->get(AutofixJobService::class);
            $this->assertInstanceOf(AutofixJobService::class, $service);
            [$outcome] = $service->pendingJob($this->input(), new DateTimeImmutable());
        } finally {
            putenv('KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD');
        }

        $this->assertSame(EnqueueOutcome::Enqueued, $outcome);
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
     * A job service over a job table with these counts
     *
     * @param array<string, int> $counts Jobs in 24 hours: `project` for the project, `mrN` for MR N
     * @param ?FakeGitlab        $gitlab The GitLab the notice writes to
     * @param ?AutofixCaps       $caps   The caps; null reads them from the environment
     *
     * @return AutofixJobService
     */
    private function countingService(array $counts, ?FakeGitlab $gitlab = null, ?AutofixCaps $caps = null): AutofixJobService
    {
        $logger = $this->createStub(Logger::class);
        $sql    = $this->createStub(SqlService::class);
        $sql->method('query')->willReturn(1);
        $sql->method('getColumn')->willReturnCallback(static function (string $statement, array $parameters = []) use ($counts): string {
            if (str_contains($statement, 'mr_iid = :mr_iid AND date_created >= :since')) {
                $mr = $parameters['mr_iid'] ?? 0;

                return (string) ($counts['mr' . (is_int($mr) ? $mr : 0)] ?? 0);
            }

            return str_contains($statement, 'date_created >= :since') ? (string) ($counts['project'] ?? 0) : '0';
        });
        $sql->method('getRows')->willReturnCallback(function (string $statement): Generator {
            if (str_contains($statement, 'ORDER BY date_created')) {
                yield ['date_created' => '2026-11-20 08:30:00'];

                return;
            }

            yield from $this->rows(null);
        });

        return new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql), $caps, new CapNoticeService($logger, ($gitlab ?? new FakeGitlab())->writer($logger)));
    }

    /**
     * @param SqlService   $sql  The SQL service
     * @param ?AutofixCaps $caps The caps; null for the defaults
     *
     * @return AutofixJobService
     */
    private function service(SqlService $sql, ?AutofixCaps $caps = null): AutofixJobService
    {
        $logger = $this->createStub(Logger::class);

        return new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql), $caps ?? new AutofixCaps());
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
