<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\DataTransferObject\Project;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\IssueFixOutcome;
use Kelpie\Enum\ProjectMode;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\EditApplyService;
use Kelpie\Service\Autofix\IssueFixPromptService;
use Kelpie\Service\Autofix\IssueFixService;
use Kelpie\Service\Autofix\IssueGateService;
use Kelpie\Service\Autofix\IssueProposeEditsService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Service\Triage\LlmFamilyService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\IssueFixService
 *
 * Runs over a real scratch git remote, a scripted model, a fake GitLab and a stubbed gate.
 *
 * What must hold:
 * - A good fix with a green gate is pushed to `kelpie/fix-<iid>-<slug>` and opens a merge request to dev with `Closes #N`
 *   and the reviewer, and the issue gets the link; dev itself is untouched
 * - Anything else (no safe edit, edits that do not apply, a protected path, a red gate) pushes nothing, comments the reason,
 *   labels the issue `kelpie::needs-human` and stops: one attempt
 * - Nothing to do (label gone, confidential, opted out, a merge request already open, not enrolled) is skipped without a model call
 * - A reached spend cap makes no model call; what a run cost is recorded
 * - What changed nothing anywhere is Retry
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\IssueFixService
 */
final class IssueFixServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string BRANCH = 'kelpie/fix-7-typo-in-a-doc-comment';

    private const string SELECT = '{"files":["src/a.php"]}';

    private const string EDIT = '{"summary":"Fixed the typo in the doc comment.","edits":[{"file":"src/a.php","old":"teh typo","new":"the typo"}]}';

    private string $base = '';

    private string $remote = '';

    private string $devSha = '';

    /**
     * The outcome records written
     *
     * @var list<ThreadOutcomeRecord>
     */
    private array $records = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueFixService::class);
    }

    /**
     * The happy path
     *
     * @return void
     */
    public function testAGreenFixOpensAMergeRequest(): void
    {
        $gitlab = new FakeFixGitlab();
        $model  = new ScriptedModelHttp([self::SELECT, self::EDIT]);

        $outcome = $this->service($gitlab, $model)->fix('org/proj', 7);

        $this->assertSame(IssueFixOutcome::Opened, $outcome);
        $this->assertNotSame('', $this->git($this->remote, ['rev-parse', '--verify', 'refs/heads/' . self::BRANCH]));
        $this->assertSame($this->devSha, $this->git($this->remote, ['rev-parse', 'refs/heads/dev']));
        $this->assertStringContainsString('the typo', $this->git($this->remote, ['show', self::BRANCH . ':src/a.php']));
        $this->assertStringContainsString('Kelpie-Autofix: issue', $this->git($this->remote, ['log', '-1', '--format=%B', self::BRANCH]));

        $mr = $this->bodyOf($gitlab, 'target_branch');
        $this->assertSame('dev', $mr['target_branch']);
        $this->assertSame(self::BRANCH, $mr['source_branch']);
        $this->assertSame([9], $mr['reviewer_ids']);
        $this->assertSame('kelpie', $mr['labels']);
        $this->assertSame('Fix #7: Typo in a doc comment!', $mr['title']);
        $this->assertIsString($mr['description']);
        $this->assertStringContainsString('Closes #7', $mr['description']);

        $this->assertCount(1, $gitlab->notes());
        $this->assertStringContainsString('merge_requests/12', $gitlab->notes()[0]);
        $this->assertSame(['kelpie::fix-proposed'], $gitlab->labelsAdded());
        $this->assertCount(1, $this->records);
        $this->assertSame(ThreadOutcome::Pushed, $this->records[0]->outcome);
        $this->assertSame('issue-7', $this->records[0]->discussionId);
        $this->assertEqualsWithDelta(0.02, (float) $this->records[0]->costUsd, 0.0001);
        $this->assertNotContains('PUT /api/v4/projects/org%2Fproj/merge_requests', $gitlab->requests());
    }

    /**
     * The issue text is sent to the model as fenced data
     *
     * @return void
     */
    public function testTheIssueIsSentAsData(): void
    {
        $model = new ScriptedModelHttp([self::SELECT, self::EDIT]);
        $this->service(new FakeFixGitlab(), $model)->fix('org/proj', 7);

        $this->assertStringContainsString("<issue>\nTypo in a doc comment!", $model->sent()[0]);
        $this->assertStringContainsString('<file path="src/a.php">', $model->sent()[1]);
    }

    /**
     * A red gate pushes nothing and hands off with the tail
     *
     * @return void
     */
    public function testARedGateHandsOff(): void
    {
        $gitlab  = new FakeFixGitlab();
        $outcome = $this->service($gitlab, new ScriptedModelHttp([self::SELECT, self::EDIT]), gate: new ProcessResult(1, "FAIL: the test <!-- kelpie:x --> @bob\n```", ''))->fix('org/proj', 7);

        $this->assertSame(IssueFixOutcome::HandedOff, $outcome);
        $this->assertNoBranchPushed();
        $this->assertCount(1, $gitlab->notes());
        $this->assertStringContainsString('the gate failed at `make check`', $gitlab->notes()[0]);
        $this->assertStringContainsString('FAIL: the test', $gitlab->notes()[0]);
        $this->assertStringNotContainsString('<!-- kelpie:x', $gitlab->notes()[0]);
        $this->assertStringNotContainsString('@bob', $gitlab->notes()[0]);
        $this->assertSame(1, substr_count($gitlab->notes()[0], '```') / 2);
        $this->assertSame(['kelpie::needs-human'], $gitlab->labelsAdded());
        $this->assertSame(ThreadOutcome::Failed, $this->records[0]->outcome);
        $this->assertNotNull($this->records[0]->costUsd);
        $this->assertNotContains('POST /api/v4/projects/org%2Fproj/merge_requests', $gitlab->requests());
    }

    /**
     * A protected path is refused by the policy
     *
     * @return void
     */
    public function testAProtectedPathHandsOff(): void
    {
        $gitlab = new FakeFixGitlab();
        $edit   = '{"summary":"x","edits":[{"file":"Makefile","old":"true","new":"false"}]}';

        $outcome = $this->service($gitlab, new ScriptedModelHttp(['{"files":["Makefile"]}', $edit]))->fix('org/proj', 7);

        $this->assertSame(IssueFixOutcome::HandedOff, $outcome);
        $this->assertNoBranchPushed();
        $this->assertStringContainsString('protected path', $gitlab->notes()[0]);
    }

    /**
     * The project's own protected globs are enforced too
     *
     * @return void
     */
    public function testAProjectProtectedGlobHandsOff(): void
    {
        $gitlab  = new FakeFixGitlab();
        $outcome = $this->service($gitlab, new ScriptedModelHttp([self::SELECT, self::EDIT]), project: new Project('org/proj', ProjectMode::Propose, protectedPaths: ['src/**']))->fix('org/proj', 7);

        $this->assertSame(IssueFixOutcome::HandedOff, $outcome);
        $this->assertNoBranchPushed();
        $this->assertStringContainsString('path this project protects (`src/a.php`)', $gitlab->notes()[0]);
    }

    /**
     * Edits that do not apply, a refusal, and no change are all hand-offs
     *
     * @return void
     */
    public function testUnusableEditsHandOff(): void
    {
        foreach (
            [
                '{"summary":"needs a design call","edits":[]}'                                 => 'needs a design call',
                '{"summary":"x","edits":[{"file":"src/a.php","old":"absent text","new":"y"}]}' => 'did not apply',
            ] as $edit => $why
        ) {
            $this->records = [];
            $gitlab        = new FakeFixGitlab();

            $this->assertSame(IssueFixOutcome::HandedOff, $this->service($gitlab, new ScriptedModelHttp([self::SELECT, $edit]))->fix('org/proj', 7));
            $this->assertStringContainsString($why, $gitlab->notes()[0]);
            $this->assertNoBranchPushed();
        }
    }

    /**
     * Nothing to do is skipped with no model call
     *
     * @return void
     */
    public function testSkips(): void
    {
        $cases = [
            'already an MR' => [$this->withOpenMr(), null],
            'confidential'  => [new FakeFixGitlab(['kelpie::fix'], true), null],
            'label gone'    => [new FakeFixGitlab([]), null],
            'not enrolled'  => [new FakeFixGitlab(), 'none'],
            'opted out'     => [new FakeFixGitlab(['kelpie::fix', 'kelpie:skip']), null],
            'project off'   => [new FakeFixGitlab(), 'off'],
        ];

        foreach ($cases as $name => [$gitlab, $project]) {
            $model = new ScriptedModelHttp([self::SELECT, self::EDIT]);
            $this->assertSame(
                IssueFixOutcome::Skipped,
                $this->service($gitlab, $model, project: $project === 'none' ? false : ($project === 'off' ? new Project('org/proj', ProjectMode::Off) : null))->fix('org/proj', 7),
                $name,
            );
            $this->assertSame([], $model->sent(), $name);
            $this->assertSame([], $gitlab->notes(), $name);
            $this->assertNoBranchPushed();
        }
    }

    /**
     * Kill switch
     *
     * @return void
     */
    public function testDisabledDoesNothing(): void
    {
        $gitlab = new FakeFixGitlab();

        $this->assertSame(IssueFixOutcome::Skipped, $this->service($gitlab, new ScriptedModelHttp([]), enabled: false)->fix('org/proj', 7));
        $this->assertSame([], $gitlab->requests());
    }

    /**
     * A reached cap makes no model call and says so on the issue
     *
     * @return void
     */
    public function testSpendCapStopsBeforeTheModel(): void
    {
        $gitlab = new FakeFixGitlab();
        $model  = new ScriptedModelHttp([self::SELECT, self::EDIT]);

        $outcome = $this->service($gitlab, $model, spentToday: 99.0)->fix('org/proj', 7);

        $this->assertSame(IssueFixOutcome::CapReached, $outcome);
        $this->assertSame([], $model->sent());
        $this->assertStringContainsString('spend cap', $gitlab->notes()[0]);
        $this->assertNoBranchPushed();
    }

    /**
     * What changed nothing anywhere is Retry: an unreadable issue, a model with no answer, a clone that fails
     *
     * @return void
     */
    public function testRetries(): void
    {
        $unreadable = (new FakeFixGitlab())->failIssue();
        $this->assertSame(IssueFixOutcome::Retry, $this->service($unreadable, new ScriptedModelHttp([]))->fix('org/proj', 7));

        $silent = new FakeFixGitlab();
        $this->assertSame(IssueFixOutcome::Retry, $this->service($silent, new ScriptedModelHttp([]))->fix('org/proj', 7));
        $this->assertSame([], $silent->notes());

        $noClone = new FakeFixGitlab();
        $this->assertSame(IssueFixOutcome::Retry, $this->service($noClone, new ScriptedModelHttp([]), gitBase: $this->base . '/missing')->fix('org/proj', 7));
    }

    /**
     * A target branch GitLab does not know is Retry, with nothing changed
     *
     * @return void
     */
    public function testUnknownTargetBranchIsRetry(): void
    {
        $gitlab       = new FakeFixGitlab();
        $model        = new ScriptedModelHttp([self::SELECT, self::EDIT]);
        $this->devSha = '';

        $this->assertSame(IssueFixOutcome::Retry, $this->service($gitlab, $model)->fix('org/proj', 7));
        $this->assertSame([], $model->sent());
        $this->assertSame([], $gitlab->notes());
    }

    /**
     * When the hand-off comment cannot be posted nobody would know, so it is Retry; the branch stays unpushed
     *
     * @return void
     */
    public function testAFailedHandOffCommentIsRetry(): void
    {
        $gitlab = (new FakeFixGitlab())->failComment();

        $outcome = $this->service($gitlab, new ScriptedModelHttp([self::SELECT, self::EDIT]), gate: new ProcessResult(1, 'red', ''))->fix('org/proj', 7);

        $this->assertSame(IssueFixOutcome::Retry, $outcome);
        $this->assertNoBranchPushed();
    }

    /**
     * A merge request that cannot be opened after the push is handed off and names the branch
     *
     * @return void
     */
    public function testMergeRequestFailureAfterPushIsHandedOff(): void
    {
        $gitlab = (new FakeFixGitlab())->failMergeRequest();

        $outcome = $this->service($gitlab, new ScriptedModelHttp([self::SELECT, self::EDIT]))->fix('org/proj', 7);

        $this->assertSame(IssueFixOutcome::HandedOff, $outcome);
        $this->assertStringContainsString(self::BRANCH . '` is pushed', $gitlab->notes()[0]);
        $this->assertStringContainsString('except that branch', $gitlab->notes()[0]);
    }

    /**
     * No reviewer configured means no reviewer field
     *
     * @return void
     */
    public function testNoReviewerConfigured(): void
    {
        $gitlab = new FakeFixGitlab();
        $this->service($gitlab, new ScriptedModelHttp([self::SELECT, self::EDIT]), reviewer: '')->fix('org/proj', 7);

        $this->assertArrayNotHasKey('reviewer_ids', $this->bodyOf($gitlab, 'target_branch'));
        $this->assertNotContains('GET /api/v4/users', $gitlab->requests());
    }

    /**
     * The branch name is a plain ref made from the title
     *
     * @return void
     */
    public function testBranchName(): void
    {
        $service = $this->service(new FakeFixGitlab(), new ScriptedModelHttp([]));

        $this->assertSame('kelpie/fix-7-typo-in-a-doc-comment', $service->branch(7, 'Typo in a doc comment!'));
        $this->assertSame('kelpie/fix-8-issue', $service->branch(8, '!!! ../.. ???'));
        $this->assertSame('kelpie/fix-9-' . str_repeat('a', 40), $service->branch(9, str_repeat('a', 90)));
    }

    /**
     * Fixture: a bare remote org/proj.git with dev holding a source file and a Makefile
     *
     * @return void
     */
    protected function setUp(): void
    {
        putenv('OPENROUTER_API_KEY=test-key');
        $this->base   = sys_get_temp_dir() . '/kelpie-ifs-test-' . bin2hex(random_bytes(6));
        $this->remote = $this->base . '/git/org/proj.git';
        mkdir($this->base . '/work/src', 0700, true);
        mkdir($this->base . '/git/org', 0700, true);
        $this->git($this->base, ['init', '--bare', '-b', 'dev', $this->remote]);
        $this->git($this->base . '/work', ['init', '-b', 'dev']);
        $this->git($this->base . '/work', ['config', 'user.name', 't']);
        $this->git($this->base . '/work', ['config', 'user.email', 't@example.com']);
        file_put_contents($this->base . '/work/src/a.php', "<?php\n// A teh typo here.\n");
        file_put_contents($this->base . '/work/Makefile', "check:\n\ttrue\n");
        $this->git($this->base . '/work', ['add', '.']);
        $this->git($this->base . '/work', ['commit', '-m', 'init']);
        $this->git($this->base . '/work', ['remote', 'add', 'origin', $this->remote]);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/dev']);
        $this->devSha  = $this->git($this->remote, ['rev-parse', 'refs/heads/dev']);
        $this->records = [];
    }

    /**
     * Remove the fixture
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
        if (str_contains($this->base, 'kelpie-ifs-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * @return FakeFixGitlab
     */
    private function withOpenMr(): FakeFixGitlab
    {
        return (new FakeFixGitlab())->withOpenMergeRequest('https://gitlab.test/org/proj/-/merge_requests/3');
    }

    /**
     * @return void
     */
    private function assertNoBranchPushed(): void
    {
        $this->assertSame('', $this->git($this->remote, ['branch', '--list', 'kelpie/*']));
        $this->assertSame($this->devSha, $this->git($this->remote, ['rev-parse', 'refs/heads/dev']));
    }

    /**
     * @param FakeFixGitlab $gitlab The fake
     * @param string        $key    A key the body has
     *
     * @return array<string, mixed>
     */
    private function bodyOf(FakeFixGitlab $gitlab, string $key): array
    {
        foreach ($gitlab->bodies() as $body) {
            if (array_key_exists($key, $body)) {
                return $body;
            }
        }

        return [];
    }

    /**
     * @param FakeFixGitlab      $gitlab     The fake GitLab
     * @param ScriptedModelHttp  $model      The scripted model
     * @param ?ProcessResult     $gate       What the gate's command answers, green by default
     * @param Project|false|null $project    The project row; false for none, null for an enrolled one
     * @param bool               $enabled    The kill switch
     * @param float              $spentToday Spend so far today
     * @param string             $reviewer   The reviewer username
     * @param ?string            $gitBase    The git base URL, the fixture by default
     *
     * @return IssueFixService
     */
    private function service(FakeFixGitlab $gitlab, ScriptedModelHttp $model, ?ProcessResult $gate = null, Project|false|null $project = null, bool $enabled = true, float $spentToday = 0.0, string $reviewer = 'reviewer', ?string $gitBase = null): IssueFixService
    {
        $gitlab->withBranchSha($this->devSha);
        $logger   = $this->createStub(Logger::class);
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'bot-token', enabled: $enabled, gitBaseUrl: $gitBase ?? $this->base . '/git');
        $client   = new OpenRouterClient($logger, $model, $factory, $factory, $json);
        $propose  = new ProposeEditsService($logger, $client, new ProposePrompt($logger), new LlmFamilyService($logger), $json);

        $row      = $project === false ? null : ($project ?? new Project('org/proj', ProjectMode::Propose, maxCostUsdDay: '5.00'));
        $projects = $this->createStub(ProjectReader::class);
        $projects->method('getByPath')->willReturn($row);
        $reader = $this->createStub(ThreadOutcomeReader::class);
        $reader->method('costToday')->willReturn($spentToday);
        $writer = $this->createStub(ThreadOutcomeWriter::class);
        $writer->method('record')->willReturnCallback(function (ThreadOutcomeRecord $record): void {
            $this->records[] = $record;
        });

        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willReturn($gate ?? new ProcessResult(0, '', ''));

        return new IssueFixService(
            $logger,
            new GitlabClientFactory($logger, $gitlab, $factory, $factory, $json, $settings),
            new IssueProposeEditsService($logger, $client, new IssueFixPromptService($logger), $propose, $json),
            new EditApplyService($logger),
            new SafetyPolicy($logger),
            new IssueGateService($logger, $runner, new GateRunner($logger, $runner)),
            new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok-not-real'),
            new SpendCapService($logger, $projects, $reader),
            $projects,
            $writer,
            $settings,
            $reviewer,
        );
    }

    /**
     * @param string   $cwd  Where
     * @param string[] $args The arguments
     *
     * @return string stdout, trimmed
     */
    private function git(string $cwd, array $args): string
    {
        $out = [];
        exec('cd ' . escapeshellarg($cwd) . ' && git ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);

        return trim(implode("\n", $out));
    }
}
