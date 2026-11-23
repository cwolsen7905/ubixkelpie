<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use ArrayObject;
use DateTimeImmutable;
use Generator;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ProjectMode;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\EditApplyService;
use Kelpie\Service\Autofix\IssueGateService;
use Kelpie\Service\Autofix\PushRoundBatchService;
use Kelpie\Service\Autofix\PushRoundCapService;
use Kelpie\Service\Autofix\PushRoundService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Autofix\StoredEditsService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Repository\Project\InMemoryProjects;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\PushRoundBatchService
 *
 * What must hold:
 * - A thread that ended with a stored fix on a push-mode project queues one round job; any other thread or mode queues none
 * - A round waits while the MR has another thread job open
 * - A round reads the open proposals at the head and pushes them as one commit, recording each as pushed, and puts the pushed label on the MR
 * - Threads whose proposal was declined, failed or made at another head are left out
 * - A project below push mode pushes nothing, whatever the job says
 * - A round of more threads than the cap pushes nothing and tells each thread once
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\PushRoundBatchService
 */
final class PushRoundBatchServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $base = '';

    private string $remote = '';

    private string $sha = '';

    private FakeGitlab $gitlab;

    private InMemoryJobStore $jobs;

    private InMemoryOutcomeStore $outcomes;

    private ScriptedProcessRunner $gateRunner;

    /**
     * The SQL statements run against the job table
     *
     * @var ArrayObject<int, string>
     */
    private ArrayObject $queries;

    /**
     * The thread whose job the job table reports as open, if any
     */
    private ?string $openThread = null;

    /**
     * The status the job table reports for that open job
     */
    private string $openStatus = 'pending';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(PushRoundBatchService::class);
    }

    /**
     * A fix stored on a push-mode project queues the round
     *
     * @return void
     */
    public function testAStoredFixQueuesTheRound(): void
    {
        $this->service()->queue('g/p', 3, $this->sha, 'd1', new DateTimeImmutable());

        $this->assertStringContainsString('INSERT INTO ubixkelpie.autofix_jobs', implode("\n", $this->queries->getArrayCopy()));
    }

    /**
     * No round for a propose-mode or off project, a thread with nothing to push, or a proposal made at another head
     *
     * @return void
     */
    public function testNothingToPushQueuesNothing(): void
    {
        foreach ([ProjectMode::Propose, ProjectMode::Off, null] as $mode) {
            $this->assertFalse($this->service(mode: $mode)->queue('g/p', 3, $this->sha, 'd1', new DateTimeImmutable()));
        }

        $this->outcomes->record(new ThreadOutcomeRecord('g/p', 3, 'd9', $this->sha, 'needs_human', 'propose', ThreadOutcome::Answered, 'Ask.'));
        $this->outcomes->record(new ThreadOutcomeRecord('g/p', 3, 'd8', $this->sha, 'fix', 'propose', ThreadOutcome::Proposed, 'No edits stored.'));
        $service = $this->service();
        $this->assertFalse($service->queue('g/p', 3, $this->sha, 'd9', new DateTimeImmutable()));
        $this->assertFalse($service->queue('g/p', 3, $this->sha, 'd8', new DateTimeImmutable()));
        $this->assertFalse($service->queue('g/p', 3, $this->sha, 'unknown', new DateTimeImmutable()));
        $this->assertFalse($service->queue('g/p', 3, str_repeat('c', 40), 'd1', new DateTimeImmutable()));
        $this->assertCount(0, $this->queries);
    }

    /**
     * A round already running is not joined: queue() says so, and the running round re-checks when it ends
     *
     * @return void
     */
    public function testARunningRoundIsNotJoined(): void
    {
        $this->openThread = 'round';
        $this->openStatus = 'running';
        $this->assertFalse($this->service()->queue('g/p', 3, $this->sha, 'd1', new DateTimeImmutable()));

        $this->openStatus = 'pending';
        $this->assertTrue($this->service()->queue('g/p', 3, $this->sha, 'd1', new DateTimeImmutable()));
    }

    /**
     * A proposal that lands while a round that pushed nothing runs sends the round round again
     *
     * @return void
     */
    public function testALateProposalMakesAnIdleRoundTryAgain(): void
    {
        $this->outcomes->record(new ThreadOutcomeRecord('g/p', 3, 'd1', $this->sha, 'fix', 'propose', ThreadOutcome::Proposed, 'Bad.', editsJson: '[{"file":"a.txt","old":"absent","new":"x"}]'));
        $outcomes = $this->outcomes;
        $sha      = $this->sha;
        $this->gitlab->onPost(static function () use ($outcomes, $sha): void {
            $outcomes->record(new ThreadOutcomeRecord('g/p', 3, 'd2', $sha, 'fix', 'propose', ThreadOutcome::Proposed, 'Late.', editsJson: '[{"file":"b.txt","old":"alpha","new":"beta"}]'));
        });
        $this->outcomes->setOutcome('g/p', 3, 'd2', ThreadOutcome::Answered);

        $error = $this->service()->run('1', 'g/p', 3, $this->sha, $this->threads('d1', 'd2'), 5);

        $this->assertSame('a proposal arrived while this round ran', $error);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(ThreadOutcome::Failed, $this->outcomes->getByThread('g/p', 3, 'd1')?->outcome);
        $this->assertSame(ThreadOutcome::Proposed, $this->outcomes->getByThread('g/p', 3, 'd2')?->outcome);
    }

    /**
     * A round waits for another open thread job of the MR, and not for itself
     *
     * @return void
     */
    public function testARoundWaitsForOpenThreadJobs(): void
    {
        $this->openThread = 'd2';
        $this->assertTrue($this->service()->waiting('g/p', 3, $this->threads('d1', 'd2'), 99));

        $this->openThread = null;
        $this->assertFalse($this->service()->waiting('g/p', 3, $this->threads('d1', 'd2'), 99));
    }

    /**
     * Two proposals at the head: one commit, one push, each recorded as pushed, the MR labelled pushed
     *
     * @return void
     */
    public function testARoundPushesTheOpenProposalsTogether(): void
    {
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->threads('d1', 'd2'), 5));

        $pushed  = trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x']));
        $message = $this->git($this->remote, ['log', '-1', '--format=%B', $pushed]);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', $pushed . '^'])));
        $this->assertStringContainsString("Thread: d1\n", $message);
        $this->assertStringContainsString("Thread: d2\n", $message);
        $this->assertSame($pushed, $this->jobs->commitSha());
        foreach (['d1', 'd2'] as $id) {
            $this->assertSame(ThreadOutcome::Pushed, $this->outcomes->getByThread('g/p', 3, $id)?->outcome);
        }
        $this->assertContains('kelpie::pushed', $this->gitlab->labels());
    }

    /**
     * Declined, failed and other-head proposals, and resolved threads, are not in the round
     *
     * @return void
     */
    public function testOnlyOpenProposalsAtTheHeadGoIn(): void
    {
        $this->outcomes->setOutcome('g/p', 3, 'd2', ThreadOutcome::Declined);
        $this->outcomes->record(new ThreadOutcomeRecord('g/p', 3, 'd3', str_repeat('c', 40), 'fix', 'propose', ThreadOutcome::Proposed, 'Old.', editsJson: '[{"file":"b.txt","old":"alpha","new":"beta"}]'));

        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->threads('d1', 'd2', 'd3', 'd4'), 5));

        $pushed  = trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x']));
        $message = $this->git($this->remote, ['log', '-1', '--format=%B', $pushed]);
        $this->assertStringContainsString("Thread: d1\n", $message);
        $this->assertStringNotContainsString('Thread: d2', $message);
        $this->assertStringNotContainsString('Thread: d3', $message);
        $this->assertSame(ThreadOutcome::Declined, $this->outcomes->getByThread('g/p', 3, 'd2')?->outcome);
    }

    /**
     * A project below push mode pushes nothing
     *
     * @return void
     */
    public function testBelowPushModeNothingIsPushed(): void
    {
        foreach ([ProjectMode::Propose, ProjectMode::Off, null] as $mode) {
            $this->assertNull($this->service(mode: $mode)->run('1', 'g/p', 3, $this->sha, $this->threads('d1', 'd2'), 5));
        }

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame([], $this->gateRunner->calls());
        $this->assertSame(ThreadOutcome::Proposed, $this->outcomes->getByThread('g/p', 3, 'd1')?->outcome);
    }

    /**
     * More threads than the cap: nothing pushed, each thread told once, however often the round runs
     *
     * @return void
     */
    public function testARoundOverTheThreadCapPushesNothing(): void
    {
        $service = $this->service(cap: new PushRoundCapService($this->createStub(Logger::class), 1, 80));

        $this->assertNull($service->run('1', 'g/p', 3, $this->sha, $this->threads('d1', 'd2'), 5));
        $this->assertNull($service->run('1', 'g/p', 3, $this->sha, $this->threads('d1', 'd2'), 5));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame([], $this->gateRunner->calls());
        foreach (['d1', 'd2'] as $id) {
            $this->assertSame(ThreadOutcome::Proposed, $this->outcomes->getByThread('g/p', 3, $id)?->outcome);
            $this->assertSame(1, $this->marked($id, 'class=over-cap'));
        }
    }

    /**
     * A round with nothing proposed does nothing
     *
     * @return void
     */
    public function testNoProposalsIsNoWork(): void
    {
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->threads('d7'), 5));

        $this->assertSame([], $this->gateRunner->calls());
    }

    /**
     * A remote with `feat/x` and `dev` at one commit, MR 3 on a fake GitLab, and two proposed fixes
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->queries = new ArrayObject();
        $this->base    = sys_get_temp_dir() . '/kelpie-batch-test-' . bin2hex(random_bytes(6));
        $this->remote  = $this->base . '/remote.git';
        mkdir($this->base . '/work', 0700, true);
        $this->git($this->base, ['init', '--bare', '-b', 'dev', $this->remote]);
        $this->git($this->base . '/work', ['init', '-b', 'dev']);
        $this->git($this->base . '/work', ['config', 'user.name', 't']);
        $this->git($this->base . '/work', ['config', 'user.email', 't@example.com']);
        file_put_contents($this->base . '/work/a.txt', "one\n");
        file_put_contents($this->base . '/work/b.txt', "alpha\n");
        $this->git($this->base . '/work', ['add', '.']);
        $this->git($this->base . '/work', ['commit', '-m', 'init']);
        $this->git($this->base . '/work', ['remote', 'add', 'origin', $this->remote]);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/dev', 'HEAD:refs/heads/feat/x']);
        $this->sha = trim($this->git($this->base . '/work', ['rev-parse', 'HEAD']));

        $this->gitlab = new FakeGitlab();
        foreach (['d1', 'd2'] as $id) {
            $this->gitlab->note($id, "Finding.\n<!-- ai-review:fp=ab12 sha=" . str_repeat('c', 40) . ' -->', author: 'kelpie-bot');
            $this->gitlab->note($id, 'Kelpie proposes this change.<!-- kelpie:reply thread=' . $id . ' class=fix head=' . $this->sha . ' -->', author: 'kelpie-bot');
        }
        $this->gitlab->mergeRequestFields(['sha' => $this->sha]);
        $this->gitlab->changedFiles(['a.txt', 'b.txt']);
        $this->jobs       = new InMemoryJobStore(status: 'running');
        $this->outcomes   = new InMemoryOutcomeStore();
        $this->gateRunner = new ScriptedProcessRunner();
        $this->outcomes->record(new ThreadOutcomeRecord('g/p', 3, 'd1', $this->sha, 'fix', 'propose', ThreadOutcome::Proposed, 'Fix the typo.', editsJson: '[{"file":"a.txt","old":"one","new":"two"}]'));
        $this->outcomes->record(new ThreadOutcomeRecord('g/p', 3, 'd2', $this->sha, 'fix', 'propose', ThreadOutcome::Proposed, 'Rename.', editsJson: '[{"file":"b.txt","old":"alpha","new":"beta"}]'));
    }

    /**
     * Remove the scratch repos
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->base, 'kelpie-batch-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * Unresolved threads with these IDs
     *
     * @param string ...$ids The discussion IDs
     *
     * @return list<Thread>
     */
    private function threads(string ...$ids): array
    {
        $now     = new DateTimeImmutable();
        $threads = [];
        foreach ($ids as $id) {
            $threads[] = new Thread($id, 1, 'kelpie-bot', 'Finding.', false, 2, $now, $now);
        }

        return $threads;
    }

    /**
     * Build the service: real git against the local remote, the fake GitLab, a scripted gate and a stubbed job table
     *
     * @param ?ProjectMode         $mode The project's mode; push by default
     * @param ?PushRoundCapService $cap  The cap, the default one when null
     *
     * @return PushRoundBatchService
     */
    private function service(?ProjectMode $mode = ProjectMode::Push, ?PushRoundCapService $cap = null): PushRoundBatchService
    {
        $logger  = $this->createStub(Logger::class);
        $writer  = $this->gitlab->writer($logger);
        $replies = new ReplyService($logger, $writer, 'kelpie-bot');

        $queries = $this->queries;
        $open    = $this->openThread;
        $status  = $this->openStatus;
        $head    = $this->sha;
        $sql     = $this->createStub(SqlService::class);
        $sql->method('query')->willReturnCallback(static function (string $statement) use ($queries): int {
            $queries[] = $statement;

            return 1;
        });
        $sql->method('lastInsertId')->willReturn('7');
        $sql->method('getColumn')->willReturn('0');
        $sql->method('getRows')->willReturnCallback(static function (string $statement, array $parameters = []) use ($open, $status, $head): Generator {
            if ($open !== null && str_contains($statement, 'autofix_jobs') && ($parameters['thread_key'] ?? null) === $open) {
                yield [
                    'after_autofix' => '0',
                    'attempt'       => '0',
                    'commit_sha'    => null,
                    'date_created'  => '2026-11-05 10:00:00',
                    'discussion_id' => $open,
                    'head_sha'      => $head,
                    'id'            => '50',
                    'mr_iid'        => '3',
                    'project_path'  => 'g/p',
                    'source'        => 'note',
                    'status'        => $status,
                    'steering'      => null,
                    'thread_key'    => $open,
                    'triage_class'  => null,
                ];
            }
        });

        return new PushRoundBatchService(
            $logger,
            $writer,
            new PushRoundService(
                $logger,
                $writer,
                $replies,
                new EditApplyService($logger),
                new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok-not-real'),
                new IssueGateService($logger, $this->gateRunner, new GateRunner($logger, $this->gateRunner)),
                $this->outcomes,
                $this->jobs,
                $this->remote,
            ),
            $cap ?? new PushRoundCapService($this->createStub(Logger::class)),
            $this->outcomes,
            new StoredEditsService($logger, new JsonService($logger)),
            InMemoryProjects::service($mode === null ? [] : ['g/p' => $mode]),
            new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql)),
        );
    }

    /**
     * How many notes on a thread carry a marker
     *
     * @param string $id     The discussion ID
     * @param string $marker The text to look for
     *
     * @return int
     */
    private function marked(string $id, string $marker): int
    {
        $count = 0;
        foreach ($this->gitlab->notes()[$id] as $note) {
            $count += str_contains($note['body'], $marker) ? 1 : 0;
        }

        return $count;
    }

    /**
     * Run git for the fixture
     *
     * @param string   $cwd  Where
     * @param string[] $args The arguments
     *
     * @return string stdout
     */
    private function git(string $cwd, array $args): string
    {
        $out = [];
        exec('cd ' . escapeshellarg($cwd) . ' && git ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);

        return implode("\n", $out);
    }
}
