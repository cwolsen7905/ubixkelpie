<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\EditApplyService;
use Kelpie\Service\Autofix\PushRoundService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\PushRoundService
 *
 * What must hold:
 * - A round of several threads is one commit and one push, with a `fix(review)` subject and one Thread line per thread
 * - Each fixed thread gets a `Fixed:` reply linking the commit; only a thread Kelpie opened is resolved
 * - A fix that edits a file the MR does not change is left out and its thread told; the rest still go in the one commit
 * - A fix whose edits no longer apply is left out; if none is left nothing is pushed and no gate runs
 * - An MR with too many files to check is refused (threads failed and told), not retried; unreadable files are retried
 * - An edit through a symlink is held to the file it lands on: not one the MR leaves unchanged, nor one outside the checkout
 * - Unreadable stored edits fail the thread only once the MR is known to be pushable
 * - A red gate pushes nothing and fails every thread of the round
 * - A moved head, a closed MR, a fork and a protected branch push nothing
 * - A rejected push or an unreadable MR is returned for a retry and sets nothing
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\PushRoundService
 */
final class PushRoundServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $base = '';

    private string $remote = '';

    private string $sha = '';

    private FakeGitlab $gitlab;

    private InMemoryJobStore $jobs;

    private InMemoryOutcomeStore $outcomes;

    private ScriptedProcessRunner $gateRunner;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(PushRoundService::class);
    }

    /**
     * Two threads in one round: one commit with both Thread lines, one push, a Fixed reply on each
     *
     * @return void
     */
    public function testARoundIsOneCommitAndOnePush(): void
    {
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));

        $pushed = trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x']));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', $pushed . '^'])));
        $this->assertSame("two\n", $this->git($this->remote, ['show', $pushed . ':a.txt']) . "\n");
        $this->assertSame("beta\n", $this->git($this->remote, ['show', $pushed . ':b.txt']) . "\n");

        $message = $this->git($this->remote, ['log', '-1', '--format=%B', $pushed]);
        $this->assertStringStartsWith('fix(review): address 2 review threads', $message);
        $this->assertStringContainsString("Thread: d1\n", $message);
        $this->assertStringContainsString("Thread: d2\n", $message);

        $this->assertCount(4, $this->gateRunner->calls());
        $this->assertSame($pushed, $this->jobs->commitSha());
        foreach (['d1', 'd2'] as $id) {
            $this->assertSame(ThreadOutcome::Applied, $this->row($id)->outcome);
            $this->assertStringStartsWith('Fixed: [`' . substr($pushed, 0, 12) . '`](https://gitlab.test/g/p/-/commit/' . $pushed . ')', $this->lastNote($id));
        }
        $this->assertSame(['d2'], $this->gitlab->resolved());
    }

    /**
     * A fix outside the MR's files is left out and told; the other goes in alone
     *
     * @return void
     */
    public function testAFixOutsideTheMrFilesIsLeftOut(): void
    {
        $this->gitlab->changedFiles(['a.txt']);

        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));

        $pushed  = trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x']));
        $message = $this->git($this->remote, ['log', '-1', '--format=%B', $pushed]);
        $this->assertStringContainsString("Thread: d1\n", $message);
        $this->assertStringNotContainsString('Thread: d2', $message);
        $this->assertStringStartsWith('fix(review): ', $message);
        $this->assertSame('alpha', trim($this->git($this->remote, ['show', $pushed . ':b.txt'])));
        $this->assertSame(ThreadOutcome::Applied, $this->row('d1')->outcome);
        $this->assertSame(ThreadOutcome::Failed, $this->row('d2')->outcome);
        $this->assertStringContainsString('`b.txt`, which this merge request does not change', $this->lastNote('d2'));
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * Edits that no longer apply leave a fix out; with none left nothing is pushed and no gate runs
     *
     * @return void
     */
    public function testEditsThatDoNotApplyAreLeftOut(): void
    {
        $fixes = [['discussionId' => 'd1', 'edits' => [new ProposedEdit('a.txt', 'absent', 'x')], 'summary' => 'Nope.']];

        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $fixes, 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(ThreadOutcome::Failed, $this->row('d1')->outcome);
        $this->assertStringContainsString('no longer apply', $this->lastNote('d1'));
        $this->assertSame([], $this->gateRunner->calls());
    }

    /**
     * A red gate fails every thread of the round and pushes nothing
     *
     * @return void
     */
    public function testARedGateFailsTheWholeRound(): void
    {
        $this->gateRunner->fail('vendor/bin/phpcs');

        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        foreach (['d1', 'd2'] as $id) {
            $this->assertSame(ThreadOutcome::Failed, $this->row($id)->outcome);
            $this->assertStringContainsString('phpcs', $this->lastNote($id));
            $this->assertStringContainsString('2 fixes are gated together', $this->lastNote($id));
        }
        $this->assertNull($this->jobs->commitSha());
    }

    /**
     * A moved head, a closed MR, a fork and a protected branch push nothing
     *
     * @return void
     */
    public function testNothingIsPushedWhenTheMrIsNotPushable(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));
        $this->assertStringContainsString('Stale', $this->lastNote('d1'));
        $this->assertStringContainsString('Stale', $this->lastNote('d2'));

        foreach ([['state' => 'closed'], ['source_project_id' => 99], ['source_branch' => 'dev']] as $fields) {
            $this->gitlab->mergeRequestFields(['sha' => $this->sha, ...$fields]);
            $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));
        }

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame([], $this->gateRunner->calls());
        $this->assertSame(ThreadOutcome::Proposed, $this->row('d1')->outcome);
    }

    /**
     * A rejected push (someone pushed first) and an unreadable MR are returned for a retry
     *
     * @return void
     */
    public function testARejectedPushIsRetried(): void
    {
        $other = $this->base . '/other';
        mkdir($other, 0700);
        $this->git($other, ['clone', $this->remote, '.']);
        $this->git($other, ['config', 'user.name', 't']);
        $this->git($other, ['config', 'user.email', 't@example.com']);
        $this->git($other, ['checkout', 'feat/x']);
        file_put_contents($other . '/c.txt', "c\n");
        $this->git($other, ['add', '.']);
        $this->git($other, ['commit', '-m', 'racing']);

        $racing = new class ($other) implements ProcessRunner {
            /**
             * @param string $other A clone that pushes while the gate runs
             */
            public function __construct(
                private readonly string $other,
            ) {
            }

            /**
             * {@inheritDoc}
             */
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- the interface's signature
            {
                exec('cd ' . escapeshellarg($this->other) . ' && git push origin HEAD:refs/heads/feat/x 2>&1');

                return new ProcessResult(0, '', '');
            }
        };

        $this->assertSame('the push was rejected', $this->service($racing)->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));
        $this->assertSame(ThreadOutcome::Proposed, $this->row('d1')->outcome);

        $this->gitlab->mergeRequestFields(['sha' => $this->sha, 'source_project_id' => 1]);
        $this->assertSame('could not read the merge request', $this->service()->run('1', '9', 4, $this->sha, $this->fixes(), 1));
    }

    /**
     * An MR with too many files to check is refused for good, not retried: every thread told, nothing pushed
     *
     * @return void
     */
    public function testTooManyChangedFilesAreRefusedNotRetried(): void
    {
        $this->gitlab->changedFiles(array_map(static function (int $n): string {
            return 'f' . $n . '.php';
        }, range(1, 500)));

        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame([], $this->gateRunner->calls());
        foreach (['d1', 'd2'] as $id) {
            $this->assertSame(ThreadOutcome::Failed, $this->row($id)->outcome);
            $this->assertStringContainsString('too many files', $this->lastNote($id));
        }
    }

    /**
     * An MR whose changed files cannot be read is retried
     *
     * @return void
     */
    public function testUnreadableChangedFilesAreRetried(): void
    {
        $this->gitlab->changedFiles(null);

        $this->assertSame('could not read the files the merge request changes', $this->service()->run('1', 'g/p', 3, $this->sha, $this->fixes(), 1));
        $this->assertSame(ThreadOutcome::Proposed, $this->row('d1')->outcome);
    }

    /**
     * An edit through a symlink the MR changes is refused when it lands on a file the MR does not change, or outside the checkout
     *
     * @return void
     */
    public function testASymlinkCannotReachAFileTheMrDoesNotChange(): void
    {
        $this->gitlab->changedFiles(['a.txt', 'link-in', 'link-out']);
        $fixes = [
            ['discussionId' => 'd1', 'edits' => [new ProposedEdit('link-in', 'alpha', 'beta')], 'summary' => 'Through a link.'],
            ['discussionId' => 'd2', 'edits' => [new ProposedEdit('link-out', 'secret', 'public')], 'summary' => 'Out of the tree.'],
        ];

        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, $fixes, 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame("secret\n", file_get_contents($this->base . '/outside.txt'));
        $this->assertSame([], $this->gateRunner->calls());
        foreach (['d1', 'd2'] as $id) {
            $this->assertSame(ThreadOutcome::Failed, $this->row($id)->outcome);
            $this->assertStringContainsString('which this merge request does not change', $this->lastNote($id));
        }
    }

    /**
     * Unreadable stored edits are told only after the MR checks: a moved head says stale, not failed
     *
     * @return void
     */
    public function testUnreadableEditsAreOnlyAnsweredOnAPushableMr(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, [['discussionId' => 'd1', 'edits' => [], 'summary' => 'x']], 1));
        $this->assertSame(ThreadOutcome::Proposed, $this->row('d1')->outcome);
        $this->assertStringContainsString('Stale', $this->lastNote('d1'));

        $this->gitlab->mergeRequestFields(['sha' => $this->sha, 'state' => 'closed']);
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, [['discussionId' => 'd1', 'edits' => [], 'summary' => 'x']], 1));
        $this->assertSame(ThreadOutcome::Proposed, $this->row('d1')->outcome);

        $this->gitlab->mergeRequestFields(['sha' => $this->sha]);
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, [['discussionId' => 'd1', 'edits' => [], 'summary' => 'x']], 1));
        $this->assertSame(ThreadOutcome::Failed, $this->row('d1')->outcome);
        $this->assertStringContainsString('could not be read', $this->lastNote('d1'));
    }

    /**
     * No fixes is no work
     *
     * @return void
     */
    public function testNoFixesDoNothing(): void
    {
        $this->assertNull($this->service()->run('1', 'g/p', 3, $this->sha, [], 1));
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * A remote with `feat/x` and `dev` at one commit holding a.txt and b.txt, a fake GitLab for MR 3 with two threads
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->base   = sys_get_temp_dir() . '/kelpie-round-test-' . bin2hex(random_bytes(6));
        $this->remote = $this->base . '/remote.git';
        mkdir($this->base . '/work', 0700, true);
        $this->git($this->base, ['init', '--bare', '-b', 'dev', $this->remote]);
        $this->git($this->base . '/work', ['init', '-b', 'dev']);
        $this->git($this->base . '/work', ['config', 'user.name', 't']);
        $this->git($this->base . '/work', ['config', 'user.email', 't@example.com']);
        file_put_contents($this->base . '/work/a.txt', "one\n");
        file_put_contents($this->base . '/work/b.txt', "alpha\n");
        file_put_contents($this->base . '/outside.txt', "secret\n");
        symlink('b.txt', $this->base . '/work/link-in');
        symlink($this->base . '/outside.txt', $this->base . '/work/link-out');
        $this->git($this->base . '/work', ['add', '.']);
        $this->git($this->base . '/work', ['commit', '-m', 'init']);
        $this->git($this->base . '/work', ['remote', 'add', 'origin', $this->remote]);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/dev', 'HEAD:refs/heads/feat/x']);
        $this->sha = trim($this->git($this->base . '/work', ['rev-parse', 'HEAD']));

        $this->gitlab = new FakeGitlab();
        $this->gitlab->note('d1', 'Please fix the typo.', author: 'alice');
        $this->gitlab->note('d2', "Finding.\n<!-- ai-review:fp=ab12 sha=" . str_repeat('c', 40) . ' -->', author: 'kelpie-bot');
        $this->gitlab->note('d2', 'Kelpie proposes this change.<!-- kelpie:reply thread=d2 class=fix head=' . $this->sha . ' -->', author: 'kelpie-bot');
        $this->gitlab->mergeRequestFields(['sha' => $this->sha]);
        $this->gitlab->changedFiles(['a.txt', 'b.txt']);
        $this->jobs       = new InMemoryJobStore(status: 'running');
        $this->outcomes   = new InMemoryOutcomeStore();
        $this->gateRunner = new ScriptedProcessRunner();
        foreach (['d1', 'd2'] as $id) {
            $this->outcomes->record(new ThreadOutcomeRecord('g/p', 3, $id, $this->sha, 'fix', 'propose', ThreadOutcome::Proposed, 'Fix.'));
        }
    }

    /**
     * Remove the scratch repos
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->base, 'kelpie-round-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * One fix for a.txt (thread d1) and one for b.txt (thread d2)
     *
     * @return list<array{discussionId: string, summary: string, edits: list<ProposedEdit>}>
     */
    private function fixes(): array
    {
        return [
            ['discussionId' => 'd1', 'edits' => [new ProposedEdit('a.txt', 'one', 'two')], 'summary' => 'Fix the typo.'],
            ['discussionId' => 'd2', 'edits' => [new ProposedEdit('b.txt', 'alpha', 'beta')], 'summary' => 'Rename.'],
        ];
    }

    /**
     * Build the service: real git against the local remote, the fake GitLab and a scripted gate
     *
     * @param ?ProcessRunner $gate What runs the gate's steps, a scripted green one by default
     *
     * @return PushRoundService
     */
    private function service(?ProcessRunner $gate = null): PushRoundService
    {
        $logger = $this->createStub(Logger::class);
        $writer = $this->gitlab->writer($logger);

        return new PushRoundService(
            $logger,
            $writer,
            new ReplyService($logger, $writer, 'kelpie-bot'),
            new EditApplyService($logger),
            new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok-not-real'),
            new GateRunner($logger, $gate ?? $this->gateRunner),
            $this->outcomes,
            $this->jobs,
            $this->remote,
        );
    }

    /**
     * The outcome row of a thread
     *
     * @param string $id The discussion ID
     *
     * @return ThreadOutcomeRecord
     */
    private function row(string $id): ThreadOutcomeRecord
    {
        $row = $this->outcomes->getByThread('g/p', 3, $id);
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * The newest note on a thread
     *
     * @param string $id The discussion ID
     *
     * @return string
     */
    private function lastNote(string $id): string
    {
        $notes = $this->gitlab->notes()[$id];
        $last  = array_pop($notes);

        return $last['body'] ?? '';
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
