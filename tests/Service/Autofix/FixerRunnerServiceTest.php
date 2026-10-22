<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use ArrayObject;
use DateTimeImmutable;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\FixOutcome;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\ClaimService;
use Kelpie\Service\Autofix\EditApplyService;
use Kelpie\Service\Autofix\FixerRunnerService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Autofix\TriageStatusService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\FixerRunnerService
 *
 * What must hold:
 * - Disabled does nothing at all
 * - A fix is proposed, never pushed: the reply carries the diff git wrote, it applies to the triaged sha, the branch is untouched
 * - A dismissal and a deferral are replies with the reason, and the thread stays open
 * - Edits that do not apply exactly, touch a protected path or break a limit become a needs-human reply, not a proposal
 * - Triage and propose models from the review's family are refused
 * - A run that stops on the MR (closed, head moved, answered) supersedes only its own job, running or pending
 * - Every thread it answers has one outcome row with the models and the cost
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

    private InMemoryOutcomeStore $outcomes;

    private string $triageClass = 'fix';

    /**
     * What the propose model answers
     *
     * @var array<string, mixed>
     */
    private array $proposal = [];

    /**
     * The model of each OpenRouter call
     *
     * @var ArrayObject<int, string>
     */
    private ArrayObject $modelCalls;

    /**
     * The user message of each OpenRouter call
     *
     * @var ArrayObject<int, string>
     */
    private ArrayObject $userMessages;

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
     * Disabled: no GitLab request, no model call, job untouched
     *
     * @return void
     */
    public function testDisabledDoesNothing(): void
    {
        $outcome = $this->service(false)->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::Disabled, $outcome);
        $this->assertSame([], $this->gitlab->requests());
        $this->assertSame([], $this->calls());
        $this->assertSame('pending', $this->jobs->status());
    }

    /**
     * A fix is a reply with the diff: nothing pushed, the thread open, the job done
     *
     * @return void
     */
    public function testFixIsProposedAsADiffAndNothingIsPushed(): void
    {
        $outcome = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(FixOutcome::Proposed, $outcome);
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/dev'])));
        $this->assertSame([], $this->gitlab->resolved());
        $this->assertSame(['triage: proposed'], $this->gitlab->labels());
        $this->assertSame('done', $this->jobs->status());

        $body = $this->lastNote();
        $this->assertStringContainsString('Kelpie proposes this change', $body);
        $this->assertStringContainsString('+two', $body);
        $this->assertStringContainsString('I have not pushed anything.', $body);
        $this->assertStringContainsString('class=fix head=' . $this->sha . ' -->', $body);
    }

    /**
     * Steering reaches both model calls as its own block, and is absent from a run without it
     *
     * @return void
     */
    public function testSteeringReachesTheTriageAndProposeCalls(): void
    {
        $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1, 'Mind the null case');

        $this->assertCount(2, $this->userMessages);
        foreach ($this->userMessages as $message) {
            $this->assertStringContainsString("<steering>\nMind the null case\n</steering>", $message);
        }
    }

    /**
     * A run with no steering sends none
     *
     * @return void
     */
    public function testNoSteeringIsSentWhenThereIsNone(): void
    {
        $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertCount(2, $this->userMessages);
        foreach ($this->userMessages as $message) {
            $this->assertStringNotContainsString('<steering>', $message);
        }
    }

    /**
     * The diff in the reply applies cleanly with `git apply` to the head it was made at
     *
     * @return void
     */
    public function testPostedDiffAppliesToTheTriagedHead(): void
    {
        $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame(1, preg_match('/```diff\n(.*)\n```/s', $this->lastNote(), $found));
        $this->assertArrayHasKey(1, $found);
        $patch = $this->base . '/proposal.patch';
        file_put_contents($patch, ($found[1] ?? '') . "\n");
        $this->git($this->base . '/work', ['checkout', '--detach', $this->sha]);

        $this->assertSame('', $this->git($this->base . '/work', ['apply', '--check', $patch]));
        $this->git($this->base . '/work', ['apply', $patch]);
        $this->assertSame("one\ntwo\n", file_get_contents($this->base . '/work/a.txt'));
    }

    /**
     * The row records the proposal, its note, the diff, both models and the summed cost
     *
     * @return void
     */
    public function testOutcomeRowRecordsTheProposal(): void
    {
        $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $row = $this->row();
        $this->assertSame(ThreadOutcome::Proposed, $row->outcome);
        $this->assertSame('fix', $row->verdict);
        $this->assertSame('propose', $row->mode);
        $this->assertSame($this->sha, $row->headSha);
        $this->assertSame('google/gemini-3-flash', $row->triageModel);
        $this->assertSame('openai/gpt-5.5', $row->proposeModel);
        $this->assertSame('anthropic', $row->reviewFamily);
        $this->assertSame(200, $row->promptTokens);
        $this->assertSame(40, $row->completionTokens);
        $this->assertEqualsWithDelta(0.002, (float) $row->costUsd, 0.0000001);
        $this->assertNotNull($row->proposalNoteId);
        $this->assertStringContainsString('"old":"one"', $row->editsJson);
        $this->assertStringContainsString('+two', $row->diff);
        $this->assertSame(['google/gemini-3-flash', 'openai/gpt-5.5'], $this->calls());
    }

    /**
     * A dismissal is a reply with the reason; the thread is not resolved and no checkout is made
     *
     * @return void
     */
    public function testDismissalIsProposedWithItsReason(): void
    {
        $this->triageClass = 'not_an_issue';

        $this->assertSame(FixOutcome::Proposed, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));

        $this->assertStringContainsString('Kelpie proposes dismissing this: It is a typo.', $this->lastNote());
        $this->assertSame([], $this->gitlab->resolved());
        $this->assertSame(['google/gemini-3-flash'], $this->calls());
        $this->assertSame('not_an_issue', $this->row()->verdict);
        $this->assertSame(ThreadOutcome::Proposed, $this->row()->outcome);
    }

    /**
     * A deferral is a reply with the reason; the thread stays open
     *
     * @return void
     */
    public function testDeferralIsProposedWithItsReason(): void
    {
        $this->triageClass = 'defer';

        $this->assertSame(FixOutcome::Proposed, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));

        $this->assertStringContainsString('Kelpie proposes deferring this: It is a typo.', $this->lastNote());
        $this->assertSame([], $this->gitlab->resolved());
        $this->assertSame('defer', $this->row()->verdict);
    }

    /**
     * An already_fixed verdict is answered and the thread resolved, with no checkout and no propose call
     *
     * @return void
     */
    public function testAlreadyFixedIsResolvedAndRecordedAsAnswered(): void
    {
        $this->triageClass = 'already_fixed';

        $this->assertSame(FixOutcome::NotAFix, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame(['d1'], $this->gitlab->resolved());
        $this->assertSame(['google/gemini-3-flash'], $this->calls());
        $this->assertSame(ThreadOutcome::Answered, $this->row()->outcome);
        $this->assertSame('done', $this->jobs->status());
    }

    /**
     * A thread that needs a person is answered with the label, no propose call
     *
     * @return void
     */
    public function testNeedsHumanIsAnsweredWithTheLabel(): void
    {
        $this->triageClass = 'needs_human';

        $this->assertSame(FixOutcome::NotAFix, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame(['kelpie::needs-human', 'triage: needs you'], $this->gitlab->labels());
        $this->assertSame(['google/gemini-3-flash'], $this->calls());
        $this->assertSame('done', $this->jobs->status());
    }

    /**
     * Old text that is not in the file: nothing applies, nothing is proposed, the thread is told
     *
     * @return void
     */
    public function testOldTextNotFoundIsRefused(): void
    {
        $this->proposal = ['edits' => [['file' => 'a.txt', 'new' => 'x', 'old' => 'absent']], 'summary' => 's'];

        $this->assertSame(FixOutcome::EditsRejected, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));

        $this->assertStringContainsString('occurs 0 times', $this->lastNote());
        $this->assertStringNotContainsString('Kelpie proposes', $this->lastNote());
        $this->assertSame(['kelpie::needs-human', 'triage: needs you'], $this->gitlab->labels());
        $this->assertSame('failed', $this->jobs->status());
        $this->assertSame(ThreadOutcome::Answered, $this->row()->outcome);
    }

    /**
     * Old text that occurs twice is refused: an edit must be unambiguous
     *
     * @return void
     */
    public function testOldTextOccurringTwiceIsRefused(): void
    {
        $this->proposal = ['edits' => [['file' => 'b.txt', 'new' => 'x', 'old' => 'dup']], 'summary' => 's'];

        $this->assertSame(FixOutcome::EditsRejected, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertStringContainsString('occurs 2 times', $this->lastNote());
    }

    /**
     * A path out of the repository is refused
     *
     * @return void
     */
    public function testPathOutsideTheRepositoryIsRefused(): void
    {
        $this->proposal = ['edits' => [['file' => '../outside.txt', 'new' => 'x', 'old' => 'one']], 'summary' => 's'];

        $this->assertSame(FixOutcome::EditsRejected, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertStringContainsString('not a plain file in the repository', $this->lastNote());
    }

    /**
     * An edit to a protected path is not proposed, and the safety policy says why
     *
     * @return void
     */
    public function testProtectedPathIsUnsafe(): void
    {
        $this->proposal = ['edits' => [['file' => '.gitlab-ci.yml', 'new' => 'stages: [x]', 'old' => 'stages: []']], 'summary' => 's'];

        $this->assertSame(FixOutcome::Unsafe, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertStringContainsString('protected path', $this->lastNote());
        $this->assertSame(['kelpie::needs-human', 'triage: needs you'], $this->gitlab->labels());
    }

    /**
     * A thread that already used up its attempts: the safety policy stops the proposal
     *
     * @return void
     */
    public function testSafetyPolicyCountsComeFromTheJobStore(): void
    {
        $this->jobs = new InMemoryJobStore(attemptsOnThread: 2);

        $this->assertSame(FixOutcome::Unsafe, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
    }

    /**
     * An empty edit list is the model's "no safe edit": the thread is told, nothing is proposed
     *
     * @return void
     */
    public function testNoEditsIsAnAnswerForAPerson(): void
    {
        $this->proposal = ['edits' => [], 'summary' => 'It needs a design call.'];

        $this->assertSame(FixOutcome::EditsRejected, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertStringContainsString('found no safe edit: It needs a design call.', $this->lastNote());
    }

    /**
     * A general comment has no file to edit
     *
     * @return void
     */
    public function testThreadWithoutAFileCannotBeEdited(): void
    {
        $now    = new DateTimeImmutable();
        $thread = new Thread('d1', 100, 'alice', 'Please fix it.', false, 1, $now, $now);

        $this->assertSame(FixOutcome::EditsRejected, $this->service()->run('1', 'g/p', 3, $thread, $this->sha, 1));
        $this->assertStringContainsString('not on a file', $this->lastNote());
    }

    /**
     * A propose model from the review's family is never asked
     *
     * @return void
     */
    public function testProposeModelInTheReviewsFamilyIsRefused(): void
    {
        putenv('KELPIE_PROPOSE_MODEL=anthropic/claude-sonnet-5.5');

        $this->assertSame(FixOutcome::EditsRejected, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame(['google/gemini-3-flash'], $this->calls());
        $this->assertStringContainsString('same family as the review', $this->lastNote());
    }

    /**
     * A triage model from the review's family is never asked, and the thread goes to a person
     *
     * @return void
     */
    public function testTriageModelInTheReviewsFamilyIsRefused(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=openrouter/anthropic/claude-haiku-5.5');

        $this->assertSame(FixOutcome::NotAFix, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('same family as the review', $this->lastNote());
        $this->assertSame(['kelpie::needs-human', 'triage: needs you'], $this->gitlab->labels());
    }

    /**
     * The review's family comes from AI_REVIEW_MODEL: with a review written by the propose model's family, that model is refused
     *
     * @return void
     */
    public function testReviewFamilyFollowsTheReviewModel(): void
    {
        putenv('AI_REVIEW_MODEL=openai/gpt-5.5');

        $this->assertSame(FixOutcome::EditsRejected, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame('openai', $this->row()->reviewFamily);
    }

    /**
     * The head moved before the reply: no proposal is posted against a head that has gone
     *
     * @return void
     */
    public function testHeadMovedBeforeTheReplyPostsNothing(): void
    {
        $gitlab = $this->gitlab;
        $gitlab->onPost(static function () use ($gitlab): void {
            $gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);
        });

        $this->assertSame(FixOutcome::HeadMoved, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertStringNotContainsString('Kelpie proposes', implode("\n", array_column($this->gitlab->notes()['d1'], 'body')));
        $this->assertSame('failed', $this->jobs->status());
    }

    /**
     * The head already differs from the triaged sha at the start: the job is superseded, no model is asked
     *
     * @return void
     */
    public function testHeadMovedAtStartSupersedes(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);

        $this->assertSame(FixOutcome::HeadMoved, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame('superseded', $this->jobs->status());
        $this->assertSame([], $this->calls());
    }

    /**
     * The worker holds the job as `running` under its lease: the runner supersedes that job when the MR closed, the head moved or the thread was answered
     *
     * @return void
     */
    public function testRunnerSupersedesItsOwnRunningJob(): void
    {
        $cases = [
            'head moved'      => static function (FakeGitlab $gitlab): void {
                $gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);
            },
            'mr closed'       => function (FakeGitlab $gitlab): void {
                $gitlab->mergeRequestFields(['sha' => $this->sha, 'state' => 'closed']);
            },
            'thread answered' => static function (FakeGitlab $gitlab): void {
                $gitlab->note('d1', 'Kelpie triage: **needs human** <!-- kelpie:reply thread=d1 class=needs_human -->', id: 205, author: 'kelpie-bot');
            },
        ];

        foreach ($cases as $name => $change) {
            $this->setUp();
            $this->jobs = new InMemoryJobStore(status: 'running');
            $this->gitlab->mergeRequestFields(['sha' => $this->sha]);
            $change($this->gitlab);

            $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

            $this->assertSame('superseded', $this->jobs->status(), $name);
            $this->assertSame([], $this->calls(), $name);
        }
    }

    /**
     * A job that is not this runner's to end stays as it is: a finished job is never superseded
     *
     * @return void
     */
    public function testSupersedeLeavesAFinishedJobAlone(): void
    {
        $this->jobs = new InMemoryJobStore(status: 'done');
        $this->gitlab->mergeRequestFields(['sha' => str_repeat('c', 40)]);

        $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);

        $this->assertSame('done', $this->jobs->status());
    }

    /**
     * A fork MR never reaches a model
     *
     * @return void
     */
    public function testForkIsSkipped(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => $this->sha, 'source_project_id' => 9]);

        $this->assertSame(FixOutcome::Skipped, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame([], $this->calls());
    }

    /**
     * `triage:off` and a draft supersede the job; a run in progress, new label or old, leaves it pending
     *
     * @return void
     */
    public function testOffDraftAndClaimedMergeRequestsAreSkipped(): void
    {
        $outcomes = [];
        foreach ([['labels' => ['triage:off']], ['draft' => true], ['labels' => ['triage: working']], ['labels' => ['kelpie::working']]] as $fields) {
            $this->gitlab->mergeRequestFields(['sha' => $this->sha, ...$fields]);
            $outcomes[] = $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1);
        }

        $this->assertSame(array_fill(0, 4, FixOutcome::Skipped), $outcomes);
        $this->assertSame([], $this->calls());
        $this->assertCount(1, $this->gitlab->notes()); // The seeded thread only: no summary, no claim
    }

    /**
     * An MR whose source branch is a protected branch never reaches a model
     *
     * @return void
     */
    public function testProtectedSourceBranchIsSkipped(): void
    {
        $this->gitlab->mergeRequestFields(['sha' => $this->sha, 'source_branch' => 'dev']);

        $this->assertSame(FixOutcome::Skipped, $this->service()->run('1', 'g/p', 3, $this->thread(), $this->sha, 1));
        $this->assertSame([], $this->calls());
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
     * A remote with `feat/x` and `dev` at one commit, a fake GitLab for MR 3, and models that answer a typo fix
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
        file_put_contents($this->base . '/work/b.txt', "dup\ndup\n");
        file_put_contents($this->base . '/work/.gitlab-ci.yml', "stages: []\n");
        $this->git($this->base . '/work', ['add', '.']);
        $this->git($this->base . '/work', ['commit', '-m', 'init']);
        $this->git($this->base . '/work', ['remote', 'add', 'origin', $this->remote]);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/dev', 'HEAD:refs/heads/feat/x']);
        $this->sha = trim($this->git($this->base . '/work', ['rev-parse', 'HEAD']));

        $this->gitlab = new FakeGitlab();
        $this->gitlab->note('d1', 'Please fix the typo.');
        $this->gitlab->mergeRequestFields(['sha' => $this->sha]);
        $this->jobs         = new InMemoryJobStore();
        $this->outcomes     = new InMemoryOutcomeStore();
        $this->triageClass  = 'fix';
        $this->modelCalls   = new ArrayObject();
        $this->userMessages = new ArrayObject();
        $this->proposal     = ['edits' => [['file' => 'a.txt', 'new' => "one\ntwo", 'old' => 'one']], 'summary' => 'Adds the missing line.'];
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1');
        putenv('AI_REVIEW_MODEL');
        putenv('KELPIE_TRIAGE_MODEL');
        putenv('KELPIE_PROPOSE_MODEL');
    }

    /**
     * Remove the scratch repos and the model settings
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->base, 'kelpie-runner-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
        putenv('AI_REVIEW_MODEL');
        putenv('KELPIE_TRIAGE_MODEL');
        putenv('KELPIE_PROPOSE_MODEL');
    }

    /**
     * Build the service: real git against the local remote, the fake GitLab and a scripted model
     *
     * @param bool $enabled KELPIE_AUTOFIX_ENABLED
     *
     * @return FixerRunnerService
     */
    private function service(bool $enabled = true): FixerRunnerService
    {
        $logger   = $this->createStub(Logger::class);
        $json     = new JsonService($logger);
        $class    = $this->triageClass;
        $proposal = $this->proposal;
        $calls    = $this->modelCalls;
        $users    = $this->userMessages;
        $http     = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function (Request $request) use ($class, $proposal, $json, $calls, $users): Psr7Response {
            $sent      = $json->decode((string) $request->getBody());
            $messages  = is_array($sent['messages'] ?? null) ? $sent['messages'] : [];
            $first     = is_array($messages[0] ?? null) ? $messages[0] : [];
            $calls[]   = is_string($sent['model'] ?? null) ? $sent['model'] : '';
            $second    = is_array($messages[1] ?? null) ? $messages[1] : [];
            $users[]   = is_string($second['content'] ?? null) ? $second['content'] : '';
            $isPropose = str_contains(is_string($first['content'] ?? null) ? $first['content'] : '', 'You propose a small code change');
            $content   = $isPropose ? $json->encode($proposal) : $json->encode(['class' => $class, 'reason' => 'It is a typo.']);
            $usage     = ['completion_tokens' => 20, 'cost' => 0.001, 'prompt_tokens' => 100];

            return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]], 'usage' => $usage]));
        });

        $open     = new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json);
        $families = new LlmFamilyService($logger);
        $writer   = $this->gitlab->writer($logger);

        return new FixerRunnerService(
            $logger,
            $enabled,
            $writer,
            new ClaimService($logger, $writer, 1800),
            new ReplyService($logger, $writer),
            new TriageService($logger, $open, new TriagePrompt($logger), new SafetyPolicy($logger), $json, $families),
            new ProposeEditsService($logger, $open, new ProposePrompt($logger), $families, $json),
            new EditApplyService($logger),
            new TriageStatusService($logger, $writer),
            new SafetyPolicy($logger),
            $families,
            new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok-not-real'),
            $this->jobs,
            $this->jobs,
            $this->outcomes,
            $this->remote,
        );
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
     * The model of each OpenRouter call so far
     *
     * @return list<string>
     */
    private function calls(): array
    {
        return array_values($this->modelCalls->getArrayCopy());
    }

    /**
     * The outcome row of thread d1, which the test expects to exist
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
