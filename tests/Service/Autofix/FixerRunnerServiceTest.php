<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Closure;
use DateTimeImmutable;
use Kelpie\Autofix\ClaimService;
use Kelpie\Autofix\GateRunner;
use Kelpie\Autofix\ReplyService;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\FixOutcome;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\FixerRunnerService;
use Kelpie\Tests\Autofix\InMemoryJobStore;
use Kelpie\Tests\Autofix\ScriptedFixerAgent;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Kelpie\Triage\SafetyPolicy;
use Kelpie\Triage\TriagePrompt;
use Kelpie\Triage\TriageService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\FixerRunnerService
 *
 * What must hold:
 * - Disabled does nothing at all
 * - A green fix is one commit on top of the triaged sha, pushed to the MR source branch, job done
 * - A branch that moved (per GitLab, or per the remote) gets no push and never loses commits
 * - A diff over the safety policy, or a red gate, pushes nothing and answers needs-human
 * - A fork MR, a protected source branch or a not-`fix` triage never reaches the agent
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\FixerRunnerService
 */
final class FixerRunnerServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $base = '';

    private string $remote = '';

    private string $sha = '';

    private FakeGitlab $gitlab;

    private InMemoryJobStore $jobs;

    /**
     * The agent's edit: called with the checkout directory and the retry's gate output
     */
    private Closure $edit;

    /**
     * The phpcs step of the gate: how many runs fail
     */
    private int $gateFailures = 0;

    private ScriptedFixerAgent $agent;

    private ScriptedProcessRunner $gate;

    private string $triageClass = 'fix';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FixerRunnerService::class);
    }

    /**
     * Disabled: no GitLab request, no agent, job untouched
     *
     * @return void
     */
    public function testDisabledDoesNothing(): void
    {
        $outcome = $this->service(false)->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::Disabled, $outcome);
        $this->assertSame([], $this->gitlab->requests());
        $this->assertSame([], $this->agent->runs());
        $this->assertSame('pending', $this->jobs->status());
    }

    /**
     * A green fix lands as one commit on the triaged sha
     *
     * @return void
     */
    public function testPushesOneCommitOnTheTriagedSha(): void
    {
        $outcome = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::Pushed, $outcome);
        $head = trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x']));
        $this->assertNotSame($this->sha, $head);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', $head . '^'])));
        $this->assertStringContainsString('Kelpie-Autofix: thread=d1 sha=' . $this->sha, $this->git($this->remote, ['log', '-1', '--format=%B', $head]));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/dev'])));
        $this->assertSame('done', $this->jobs->status());
        $this->assertSame($head, $this->jobs->commitSha());
        $this->assertSame('fix', $this->jobs->triageClass());
        $this->assertSame(1, $this->jobs->attempt());
        $this->assertSame([], $this->gitlab->labels());

        $bodies = implode("\n", array_column($this->gitlab->notes()['d1'], 'body'));
        $this->assertStringContainsString('Kelpie fixed this in https://gitlab.test/g/p/-/commit/' . $head . ':', $bodies);
        $this->assertStringContainsString('class=fix -->', $bodies);
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * Nothing pushed, nothing announced
     *
     * @return void
     */
    public function testNoFixReplyWithoutAPush(): void
    {
        $this->edit = static function (): bool {
            return true;
        };

        $this->assertSame(FixOutcome::NoChange, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));

        $this->assertStringNotContainsString('Kelpie fixed this', implode("\n", array_column($this->gitlab->notes()['d1'], 'body')));
    }

    /**
     * An already_fixed verdict is answered and the thread resolved, with no checkout
     *
     * @return void
     */
    public function testAlreadyFixedIsResolved(): void
    {
        $this->triageClass = 'already_fixed';

        $this->assertSame(FixOutcome::NotAFix, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame([], $this->agent->runs());
        $this->assertSame(['d1'], $this->gitlab->resolved());
    }

    /**
     * GitLab says the head moved before the push: nothing is pushed
     *
     * @return void
     */
    public function testHeadMovedBeforePushPushesNothing(): void
    {
        $gitlab     = $this->gitlab;
        $this->edit = static function (string $dir) use ($gitlab): bool {
            file_put_contents($dir . '/a.txt', "fixed\n");
            $gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);

            return true;
        };

        $outcome = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::HeadMoved, $outcome);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame('failed', $this->jobs->status());
    }

    /**
     * The head already differs from the triaged sha at the start: the job is superseded, the agent never runs
     *
     * @return void
     */
    public function testHeadMovedAtStartSupersedes(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);

        $outcome = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::HeadMoved, $outcome);
        $this->assertSame('superseded', $this->jobs->status());
        $this->assertSame([], $this->agent->runs());
    }

    /**
     * The remote branch moved under us (GitLab not yet aware): the server rejects, the other commit stays
     *
     * @return void
     */
    public function testRemoteMovedIsRejectedNotOverwritten(): void
    {
        $work       = $this->base . '/work';
        $edit       = $this->edit;
        $this->edit = function (string $dir, ?string $failure) use ($work, $edit): bool {
            $this->git($work, ['commit', '--allow-empty', '-m', 'someone else']);
            $this->git($work, ['push', 'origin', 'HEAD:refs/heads/feat/x']);

            return (bool) $edit($dir, $failure);
        };

        $outcome = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);
        $theirs  = trim($this->git($work, ['rev-parse', 'HEAD']));

        $this->assertSame(FixOutcome::PushRejected, $outcome);
        $this->assertSame($theirs, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame('failed', $this->jobs->status());
    }

    /**
     * A diff that touches a protected path is not gated, not pushed, and answered needs-human
     *
     * @return void
     */
    public function testProtectedPathIsUnsafe(): void
    {
        $this->edit = static function (string $dir): bool {
            file_put_contents($dir . '/.gitlab-ci.yml', "stages: []\n");

            return true;
        };

        $outcome = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::Unsafe, $outcome);
        $this->assertSame([], $this->gateCalls());
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(['kelpie::needs-human'], $this->gitlab->labels());
        $this->assertStringContainsString('protected path', $this->lastNote());
    }

    /**
     * A red gate is retried once with its output, then answered needs-human with nothing pushed
     *
     * @return void
     */
    public function testRedGateRetriesOnceThenGivesUp(): void
    {
        $this->gateFailures = 2;

        $outcome = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::GateFailed, $outcome);
        $this->assertCount(2, $this->agent->runs());
        $runs = $this->agent->runs();
        $this->assertNull($runs[0]);
        $this->assertStringContainsString('vendor/bin/phpcs failed', (string) $runs[1]);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame(['kelpie::needs-human'], $this->gitlab->labels());
        $this->assertSame('failed', $this->jobs->status());
    }

    /**
     * A red gate that the retry fixes is pushed
     *
     * @return void
     */
    public function testRetryThatPassesIsPushed(): void
    {
        $this->gateFailures = 1;

        $this->assertSame(FixOutcome::Pushed, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertCount(2, $this->agent->runs());
    }

    /**
     * An agent that changes nothing is answered needs-human
     *
     * @return void
     */
    public function testNoChange(): void
    {
        $this->edit = static function (string $dir): bool {
            return is_dir($dir);
        };

        $this->assertSame(FixOutcome::NoChange, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame(['kelpie::needs-human'], $this->gitlab->labels());
    }

    /**
     * An agent that fails pushes nothing
     *
     * @return void
     */
    public function testAgentFailure(): void
    {
        $this->edit = static function (string $dir): bool {
            return $dir === '';
        };

        $this->assertSame(FixOutcome::AgentFailed, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame('failed', $this->jobs->status());
    }

    /**
     * A thread already used up its attempts: the safety policy stops the fix after the agent ran, nothing pushed
     *
     * @return void
     */
    public function testSafetyPolicyCountsComeFromTheJobStore(): void
    {
        $this->jobs = new InMemoryJobStore(attemptsOnThread: 2);

        $this->assertSame(FixOutcome::Unsafe, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
    }

    /**
     * A fork MR never reaches the agent
     *
     * @return void
     */
    public function testForkIsSkipped(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => $this->sha, 'source_project_id' => 9]);

        $this->assertSame(FixOutcome::Skipped, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame([], $this->agent->runs());
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
    }

    /**
     * An MR whose source branch is a protected branch never reaches the agent
     *
     * @return void
     */
    public function testProtectedSourceBranchIsSkipped(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => $this->sha, 'source_branch' => 'dev']);

        $this->assertSame(FixOutcome::Skipped, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame([], $this->agent->runs());
    }

    /**
     * A thread not triaged `fix` is answered on the not-safe path and the agent never runs
     *
     * @return void
     */
    public function testNotAFixNeverReachesTheAgent(): void
    {
        $this->triageClass = 'needs_human';

        $this->assertSame(FixOutcome::NotAFix, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame([], $this->agent->runs());
        $this->assertSame(['kelpie::needs-human'], $this->gitlab->labels());
        $this->assertSame('done', $this->jobs->status());
    }

    /**
     * The claim and the working label are released after a run, and nothing is ever deleted on GitLab
     *
     * @return void
     */
    public function testClaimReleasedAndNoDelete(): void
    {
        $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertContains('PUT /projects/1/merge_requests/3', $this->gitlab->requests());
        $bodies = implode("\n", array_column($this->gitlab->notes()['d1'], 'body'));
        $this->assertStringContainsString('kelpie:release thread=d1', $bodies);
        $this->assertStringNotContainsString('kelpie:claim', $bodies);
        foreach ($this->gitlab->requests() as $request) {
            $this->assertStringStartsNotWith('DELETE', $request);
        }
    }

    /**
     * A remote with `feat/x` and `dev` at one commit, a fake GitLab for MR 3, a green gate and an agent that edits a.txt
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->base   = sys_get_temp_dir() . '/kelpie-runner-test-' . bin2hex(random_bytes(6));
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
        $this->gitlab->mergeRequestFields(['sha' => $this->sha]);
        $this->jobs         = new InMemoryJobStore();
        $this->gateFailures = 0;
        $this->triageClass  = 'fix';
        $this->edit         = static function (string $dir): bool {
            file_put_contents($dir . '/a.txt', "one\ntwo\n");

            return true;
        };
    }

    /**
     * Remove the scratch repos
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->base, 'kelpie-runner-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * Build the service: real git against the local remote, the fake GitLab, a scripted gate and agent
     *
     * @param bool $enabled KELPIE_AUTOFIX_ENABLED
     *
     * @return FixerRunnerService
     */
    private function service(bool $enabled = true): FixerRunnerService
    {
        $logger = $this->createStub(Logger::class);
        $json   = new JsonService($logger);
        $gitlab = $this->gitlab;
        $class  = $this->triageClass;
        $http   = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function () use ($class, $json): Psr7Response {
            $content = $json->encode(['class' => $class, 'reason' => 'It is a typo.']);

            return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
        });
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1');

        $triage = new TriageService(
            $logger,
            new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
        );

        $writer      = $gitlab->writer($logger);
        $this->agent = new ScriptedFixerAgent($this->edit);
        $this->gate  = new ScriptedProcessRunner();
        $this->gate->fail('vendor/bin/phpcs', $this->gateFailures);

        return new FixerRunnerService(
            $logger,
            $enabled,
            $writer,
            new ClaimService($logger, $writer, 1800),
            new ReplyService($logger, $writer),
            $triage,
            new SafetyPolicy($logger),
            $this->agent,
            new GateRunner($logger, $this->gate),
            new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok-not-real'),
            $this->jobs,
            $this->jobs,
            $this->remote,
        );
    }

    /**
     * The program names the gate ran
     *
     * @return string[]
     */
    private function gateCalls(): array
    {
        return array_map(static function (array $call): string {
            return $call['argv'][0];
        }, $this->gate->calls());
    }

    /**
     * @return Thread
     */
    private function thread(): Thread
    {
        $now = new DateTimeImmutable();

        return new Thread('d1', 100, 'alice', 'Please fix the typo.', false, 1, $now, $now, 'a.txt', 1);
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
