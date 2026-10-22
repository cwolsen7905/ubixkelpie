<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\EditApplyService;
use Kelpie\Service\Autofix\ProposalReactionService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\ProposalReactionService
 *
 * What must hold:
 * - A thumbs-up applies the stored edits at the recorded head, runs the gate, commits with a Thread line and the loop-guard trailer, pushes to the source branch, marks the proposal applied and says so
 * - A red gate pushes nothing, marks the proposal failed and says which step
 * - A head that moved gets a "stale" reply and changes nothing, before and after the checkout
 * - Edits that no longer apply push nothing and mark the proposal failed
 * - A thumbs-down marks the proposal declined and leaves the thread open, without a checkout or a gate
 * - A proposal that is not open any more, a closed MR and a protected source branch do nothing
 * - Kelpie never awards an emoji
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\ProposalReactionService
 */
final class ProposalReactionServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(ProposalReactionService::class);
    }

    /**
     * A thumbs-up pushes the proposal's edits as one commit on the source branch and says so
     *
     * @return void
     */
    public function testThumbsUpPushesTheProposal(): void
    {
        $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', true, 1));

        $pushed = trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x']));
        $this->assertNotSame($this->sha, $pushed);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', $pushed . '^'])));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/dev'])));
        $this->assertSame("one\ntwo\n", $this->git($this->remote, ['show', $pushed . ':a.txt']) . "\n");

        $message = $this->git($this->remote, ['log', '-1', '--format=%B', $pushed]);
        $this->assertStringStartsWith('fix(review): ', $message);
        $this->assertStringContainsString("Thread: d1\n", $message);
        $this->assertStringContainsString('Kelpie-Autofix: propose', $message);

        $this->assertSame(ThreadOutcome::Applied, $this->row()->outcome);
        $this->assertSame($pushed, $this->jobs->commitSha());
        $this->assertSame('done', $this->jobs->status());
        $this->assertStringContainsString('Kelpie fixed this in `' . substr($pushed, 0, 12) . '`', $this->lastNote());
        $this->assertCount(4, $this->gateRunner->calls());
        $this->assertSame([], $this->gitlab->awards());
    }

    /**
     * A red gate: nothing pushed, the proposal failed, the thread told which step
     *
     * @return void
     */
    public function testARedGatePushesNothing(): void
    {
        $this->gateRunner->fail('vendor/bin/phpstan');

        $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', true, 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(ThreadOutcome::Failed, $this->row()->outcome);
        $this->assertStringContainsString('gate is red', $this->lastNote());
        $this->assertStringContainsString('phpstan', $this->lastNote());
        $this->assertStringContainsString('I did not push it', $this->lastNote());
        $this->assertNull($this->jobs->commitSha());
    }

    /**
     * A head that has moved: told "stale", nothing set, nothing pushed, no checkout
     *
     * @return void
     */
    public function testAMovedHeadIsStale(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);

        $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', true, 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(ThreadOutcome::Proposed, $this->row()->outcome);
        $this->assertStringContainsString('Stale', $this->lastNote());
        $this->assertStringContainsString('class=stale', $this->lastNote());
        $this->assertSame([], $this->gateRunner->calls());
    }

    /**
     * A head that moves while the gate runs is caught before the push
     *
     * @return void
     */
    public function testAHeadThatMovesDuringTheGateIsStale(): void
    {
        $gitlab = $this->gitlab;
        $moving = new class ($gitlab) implements ProcessRunner {
            /**
             * @param FakeGitlab $gitlab The MR whose head moves while the gate runs
             */
            public function __construct(
                private readonly FakeGitlab $gitlab,
            ) {
            }

            /**
             * {@inheritDoc}
             */
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- the interface's signature
            {
                $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);

                return new ProcessResult(0, '', '');
            }
        };

        $this->assertNull($this->service(gate: $moving)->run('1', 'g/p', 3, 'd1', true, 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(ThreadOutcome::Proposed, $this->row()->outcome);
        $this->assertStringContainsString('Stale', $this->lastNote());
    }

    /**
     * Edits whose old text is gone: the proposal fails and nothing is pushed
     *
     * @return void
     */
    public function testEditsThatNoLongerApplyPushNothing(): void
    {
        $this->outcomes->record($this->record('[{"file":"a.txt","new":"x","old":"absent"}]'));

        $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', true, 1));

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(ThreadOutcome::Failed, $this->row()->outcome);
        $this->assertStringContainsString('no longer apply', $this->lastNote());
        $this->assertSame([], $this->gateRunner->calls());
    }

    /**
     * Stored edits that are not a list of edits fail the proposal
     *
     * @return void
     */
    public function testUnreadableEditsFailTheProposal(): void
    {
        $this->outcomes->record($this->record('{"not":"a list"}'));

        $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', true, 1));
        $this->assertSame(ThreadOutcome::Failed, $this->row()->outcome);

        $this->outcomes->record($this->record('not json'));
        $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', true, 1));
        $this->assertSame(ThreadOutcome::Failed, $this->row()->outcome);
    }

    /**
     * A thumbs-down only marks the proposal declined: no checkout, no gate, no push, the thread stays open
     *
     * @return void
     */
    public function testThumbsDownDeclines(): void
    {
        $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', false, 1));

        $this->assertSame(ThreadOutcome::Declined, $this->row()->outcome);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame([], $this->gateRunner->calls());
        $this->assertSame([], $this->gitlab->resolved());
        $this->assertStringContainsString('class=declined', $this->lastNote());
        $this->assertStringContainsString('`Triage:', $this->lastNote());
    }

    /**
     * A proposal that was already applied, declined or never recorded is not acted on again
     *
     * @return void
     */
    public function testAProposalNotOpenIsLeftAlone(): void
    {
        $service = $this->service();
        $this->outcomes->setOutcome('g/p', 3, 'd1', ThreadOutcome::Applied);

        $this->assertNull($service->run('1', 'g/p', 3, 'd1', true, 1));
        $this->assertNull($service->run('1', 'g/p', 3, 'd1', false, 1));
        $this->assertNull($service->run('1', 'g/p', 3, 'unknown', true, 1));

        $this->assertSame(ThreadOutcome::Applied, $this->row()->outcome);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertCount(2, $this->gitlab->notes()['d1']);
    }

    /**
     * A closed MR, a fork and a protected source branch are never pushed to
     *
     * @return void
     */
    public function testNoPushToAClosedForkedOrProtectedBranch(): void
    {
        foreach ([['state' => 'closed'], ['source_project_id' => 99], ['source_branch' => 'dev']] as $fields) {
            $this->gitlab->mergeRequestFields(['sha' => $this->sha, ...$fields]);

            $this->assertNull($this->service()->run('1', 'g/p', 3, 'd1', true, 1));
        }

        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/dev'])));
        $this->assertSame(ThreadOutcome::Proposed, $this->row()->outcome);
    }

    /**
     * A checkout that cannot be made is retried, not answered
     *
     * @return void
     */
    public function testACheckoutFailureIsRetried(): void
    {
        $result = $this->service(remote: $this->base . '/missing.git')->run('1', 'g/p', 3, 'd1', true, 1);

        $this->assertSame('the scratch checkout could not be made', $result);
        $this->assertSame(ThreadOutcome::Proposed, $this->row()->outcome);
    }

    /**
     * A remote with `feat/x` and `dev` at one commit, a fake GitLab for MR 3, and a proposal recorded for thread d1
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->base   = sys_get_temp_dir() . '/kelpie-react-test-' . bin2hex(random_bytes(6));
        $this->remote = $this->base . '/remote.git';
        mkdir($this->base . '/work', 0700, true);
        $this->git($this->base, ['init', '--bare', '-b', 'dev', $this->remote]);
        $this->git($this->base . '/work', ['init', '-b', 'dev']);
        $this->git($this->base . '/work', ['config', 'user.name', 't']);
        $this->git($this->base . '/work', ['config', 'user.email', 't@example.com']);
        file_put_contents($this->base . '/work/a.txt', "one\n");
        $this->git($this->base . '/work', ['add', '.']);
        $this->git($this->base . '/work', ['commit', '-m', 'init']);
        $this->git($this->base . '/work', ['remote', 'add', 'origin', $this->remote]);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/dev', 'HEAD:refs/heads/feat/x']);
        $this->sha = trim($this->git($this->base . '/work', ['rev-parse', 'HEAD']));

        $this->gitlab = new FakeGitlab();
        $this->gitlab->note('d1', 'Please fix the typo.');
        $this->gitlab->note('d1', 'Kelpie proposes this change.', id: 105, author: 'kelpie-bot');
        $this->gitlab->mergeRequestFields(['sha' => $this->sha]);
        $this->jobs       = new InMemoryJobStore(status: 'running');
        $this->outcomes   = new InMemoryOutcomeStore();
        $this->gateRunner = new ScriptedProcessRunner();
        $this->outcomes->record($this->record('[{"file":"a.txt","new":"one\ntwo","old":"one"}]'));
    }

    /**
     * Remove the scratch repos
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->base, 'kelpie-react-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * The proposal on thread d1
     *
     * @param string $editsJson The stored edits
     *
     * @return ThreadOutcomeRecord
     */
    private function record(string $editsJson): ThreadOutcomeRecord
    {
        return new ThreadOutcomeRecord('g/p', 3, 'd1', $this->sha, 'fix', 'propose', ThreadOutcome::Proposed, 'Adds the missing line.', proposalNoteId: 105, editsJson: $editsJson);
    }

    /**
     * Build the service: real git against the local remote, the fake GitLab and a scripted gate
     *
     * @param ?string        $remote The git remote, the fixture by default
     * @param ?ProcessRunner $gate   What runs the gate's steps, a scripted green one by default
     *
     * @return ProposalReactionService
     */
    private function service(?string $remote = null, ?ProcessRunner $gate = null): ProposalReactionService
    {
        $logger = $this->createStub(Logger::class);
        $writer = $this->gitlab->writer($logger);

        return new ProposalReactionService(
            $logger,
            $writer,
            new ReplyService($logger, $writer),
            new EditApplyService($logger),
            new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok-not-real'),
            new GateRunner($logger, $gate ?? $this->gateRunner),
            $this->outcomes,
            $this->outcomes,
            $this->jobs,
            new JsonService($logger),
            $remote ?? $this->remote,
        );
    }

    /**
     * The outcome row of thread d1
     *
     * @return ThreadOutcomeRecord
     */
    private function row(): ThreadOutcomeRecord
    {
        $row = $this->outcomes->getByThread('g/p', 3, 'd1');
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * The newest note on thread d1
     *
     * @return string
     */
    private function lastNote(): string
    {
        $notes = $this->gitlab->notes()['d1'];
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
