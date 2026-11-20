<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
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
use Kelpie\Service\Autofix\IssueFixWorkerService;
use Kelpie\Service\Autofix\IssueGateService;
use Kelpie\Service\Autofix\IssueProposeEditsService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Kelpie\Tests\Service\Triage\TriageLlms;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\IssueFixWorkerService
 *
 * What must hold:
 * - With autofix off nothing is claimed, so the jobs stay pending (the kill switch)
 * - Only the enrolled projects are offered to the claim
 * - Any outcome but Retry settles the job with the outcome; Retry is tried once more after five minutes, then given up on
 * - A handed-off fix settles too: a repeat step finds no due job, so a second comment is never posted and nothing is pushed
 * - An exception in the fix or the claim never escapes the loop, and a lost lock race is not a step
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\IssueFixWorkerService
 */
final class IssueFixWorkerServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string NOW = '2026-10-19 12:00:00 UTC';

    private string $base = '';

    private string $remote = '';

    private string $devSha = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueFixWorkerService::class);
    }

    /**
     * Disabled, the queue is not even asked
     *
     * @return void
     */
    public function testDisabledClaimsNothing(): void
    {
        $queue = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(1, 'org/proj', 7, 0)]);

        $this->assertFalse($this->worker($queue, new FakeFixGitlab())->step(false, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame([], $queue->claims());
    }

    /**
     * The claim is offered the enrolled projects, and an empty queue is not a step
     *
     * @return void
     */
    public function testAnEmptyQueueIsIdle(): void
    {
        $queue = new InMemoryIssueQueue();

        $this->assertFalse($this->worker($queue, new FakeFixGitlab())->step(true, ['org/proj', 'org/other'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['fix:org/proj,org/other'], $queue->claims());
    }

    /**
     * A settled job is done with its outcome
     *
     * @return void
     */
    public function testASettledJobIsDone(): void
    {
        $queue = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)]);

        $this->assertTrue($this->worker($queue, new FakeFixGitlab([]))->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['lock 4 0', 'done 4 skipped'], $queue->settled());
    }

    /**
     * A Retry is put back for five minutes, and the second one is given up on
     *
     * @return void
     */
    public function testRetryThenGiveUp(): void
    {
        $unreadable = (new FakeFixGitlab())->failIssue();
        $first      = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)]);
        $second     = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 1)]);

        $this->assertTrue($this->worker($first, $unreadable)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertTrue($this->worker($second, $unreadable)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));

        $this->assertSame('retry 4 12:05:00 nothing could be done this time', $first->settled()[1]);
        $this->assertStringStartsWith('failed 4 nothing could be done after 2 tries', $second->settled()[1]);
    }

    /**
     * A handed-off fix settles the job: a repeat step finds nothing due, so no second comment and no push
     *
     * @return void
     */
    public function testAHandedOffFixIsNotRepeated(): void
    {
        $gitlab = new FakeFixGitlab();
        $queue  = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)]);
        $worker = $this->worker($queue, $gitlab, gate: new ProcessResult(1, 'red', ''));

        $this->assertTrue($worker->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertSame(['lock 4 0', 'done 4 handed_off'], $queue->settled());
        $this->assertCount(1, $gitlab->notes());

        $this->assertFalse($worker->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertCount(1, $gitlab->notes());
        $this->assertSame('', $this->git($this->remote, ['branch', '--list', 'kelpie/*']));
        $this->assertSame($this->devSha, $this->git($this->remote, ['rev-parse', 'refs/heads/dev']));
    }

    /**
     * An exception in the fix is a Retry, never thrown out of the loop
     *
     * @return void
     */
    public function testAThrowingFixIsARetry(): void
    {
        $queue = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)]);

        $this->assertTrue($this->worker($queue, new FakeFixGitlab(), throwing: true)->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
        $this->assertStringStartsWith('retry 4', $queue->settled()[1]);
    }

    /**
     * A lost lock race is not a step, and a claim that throws is swallowed
     *
     * @return void
     */
    public function testLostRaceAndClaimFailure(): void
    {
        $lost = (new InMemoryIssueQueue())->withFixJobs([new IssueTriageJob(4, 'org/proj', 7, 0)])->loseTheLock();
        $this->assertFalse($this->worker($lost, new FakeFixGitlab())->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));

        $broken = $this->createStub(IssueTriageJobReader::class);
        $broken->method('getDueFixJob')->willThrowException(new RuntimeException('column missing'));
        $worker = new IssueFixWorkerService($this->createStub(Logger::class), $broken, new InMemoryIssueQueue(), $this->fixer(new FakeFixGitlab()));
        $this->assertFalse($worker->step(true, ['org/proj'], new DateTimeImmutable(self::NOW)));
    }

    /**
     * Fixture: a bare remote org/proj.git with dev holding a source file and a Makefile
     *
     * @return void
     */
    protected function setUp(): void
    {
        putenv('OPENROUTER_API_KEY=test-key');
        $this->base   = sys_get_temp_dir() . '/kelpie-ifw-test-' . bin2hex(random_bytes(6));
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
        $this->devSha = $this->git($this->remote, ['rev-parse', 'refs/heads/dev']);
    }

    /**
     * Remove the fixture
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
        if (str_contains($this->base, 'kelpie-ifw-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * @param InMemoryIssueQueue $queue    The queue
     * @param FakeFixGitlab      $gitlab   The fake GitLab
     * @param bool               $throwing Whether reading the project throws
     * @param ?ProcessResult     $gate     What the gate's command answers, green by default
     *
     * @return IssueFixWorkerService
     */
    private function worker(InMemoryIssueQueue $queue, FakeFixGitlab $gitlab, bool $throwing = false, ?ProcessResult $gate = null): IssueFixWorkerService
    {
        return new IssueFixWorkerService($this->createStub(Logger::class), $queue, $queue, $this->fixer($gitlab, $throwing, $gate));
    }

    /**
     * An issue fix service whose only live parts are the issue read and the project read
     *
     * @param FakeFixGitlab  $gitlab   The fake GitLab
     * @param bool           $throwing Whether reading the project throws
     * @param ?ProcessResult $gate     What the gate's command answers, green by default
     *
     * @return IssueFixService
     */
    private function fixer(FakeFixGitlab $gitlab, bool $throwing = false, ?ProcessResult $gate = null): IssueFixService
    {
        $logger   = $this->createStub(Logger::class);
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'bot-token', enabled: true, gitBaseUrl: $this->base . '/git');
        $client   = new OpenRouterClient($logger, new ScriptedModelHttp(['{"files":["src/a.php"]}', '{"summary":"Fixed the typo in the doc comment.","edits":[{"file":"src/a.php","old":"teh typo","new":"the typo"}]}']), $factory, $factory, $json);
        $propose  = new ProposeEditsService($logger, $client, new ProposePrompt($logger), new LlmFamilyService($logger), $json, TriageLlms::make($logger));
        $projects = $this->createStub(ProjectReader::class);
        if ($throwing) {
            $projects->method('getByPath')->willThrowException(new RuntimeException('database down'));
        } else {
            $projects->method('getByPath')->willReturn(new Project('org/proj', ProjectMode::Propose, maxCostUsdDay: '5.00'));
        }
        $gitlab->withBranchSha($this->devSha);
        $reader = $this->createStub(ThreadOutcomeReader::class);
        $reader->method('costToday')->willReturn(0.0);
        $writer = $this->createStub(ThreadOutcomeWriter::class);
        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willReturn($gate ?? new ProcessResult(0, '', ''));

        return new IssueFixService(
            $logger,
            new GitlabClientFactory($logger, $gitlab, $factory, $factory, $json, $settings),
            new IssueProposeEditsService($logger, $client, new IssueFixPromptService($logger), $propose, $json),
            new EditApplyService($logger),
            new SafetyPolicy($logger),
            new IssueGateService($logger, $runner, new GateRunner($logger, $runner)),
            new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok'),
            new SpendCapService($logger, $projects, $reader),
            $projects,
            $writer,
            $settings,
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
