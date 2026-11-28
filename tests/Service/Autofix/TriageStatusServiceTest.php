<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\MergeRequestInfo;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\ThreadResult;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageStatus;
use Kelpie\Service\Autofix\TriageStatusService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\TriageStatusService
 *
 * What must hold:
 * - A run start sets `triage: working`, creates missing labels, posts one note and puts 👀 on each thread
 * - A run end edits that same note, removes `working` and leaves exactly one outcome label (needs you, then proposed, then pushed)
 * - A later round replaces the labels of the earlier one, they never stack
 * - `triage:off`, a draft and a working label (old or new) are told apart
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\TriageStatusService
 */
final class TriageStatusServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * The fake GitLab
     */
    private FakeGitlab $gitlab;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageStatusService::class);
    }

    /**
     * Start: label, project labels, one note, 👀 on every thread
     *
     * @return void
     */
    public function testStartMarksTheMergeRequest(): void
    {
        $run = $this->service()->start('1', 3, [$this->thread('d1', 11), $this->thread('d2', 12)]);

        $this->assertNotNull($run);
        $this->assertSame(['kelpie::working'], $this->gitlab->labels());
        $this->assertEqualsCanonicalizing(['kelpie::working', 'kelpie::pushed', 'kelpie::proposed', 'kelpie::needs-you'], $this->gitlab->projectLabels());
        $this->assertSame(['11:eyes', '12:eyes'], $this->gitlab->awards());
        $this->assertStringContainsString('working on 2 threads', $this->summaryNote($run->noteId)['body']);
        $this->assertContains('POST /projects/1/merge_requests/3/notes', $this->gitlab->requests());
        $this->assertNotContains('POST /projects/1/merge_requests/3/discussions', $this->gitlab->requests());
    }

    /**
     * Labels the project already has are not created again
     *
     * @return void
     */
    public function testExistingProjectLabelsAreNotRecreated(): void
    {
        $this->service()->start('1', 3, [$this->thread('d1', 11)]);
        $this->service()->start('1', 3, [$this->thread('d1', 11)]);

        $this->assertCount(4, $this->gitlab->projectLabels());
        $this->assertSame(['11:eyes'], $this->gitlab->awards()); // GitLab refuses a second 👀, which is not an error here
    }

    /**
     * End: the same note becomes the outcome, `working` goes, one label stays
     *
     * @return void
     */
    public function testFinishEditsTheNoteAndLeavesOneLabel(): void
    {
        $service = $this->service();
        $run     = $service->start('1', 3, [$this->thread('d1', 11)]);

        $left = $service->finish('1', 3, $run, ['d1' => new ThreadResult(TriageStatus::Pushed, 'fixed in `abc`')]);

        $this->assertSame(TriageStatus::Pushed, $left);
        $this->assertSame(['kelpie::pushed'], $this->gitlab->labels());
        $this->assertNotNull($run);
        $this->assertCount(1, array_merge(...array_values($this->gitlab->notes()))); // Edited, not a second note
        $this->assertContains('PUT /projects/1/merge_requests/3/notes/' . $run->noteId, $this->gitlab->requests());
        $body = $this->summaryNote($run->noteId)['body'];
        $this->assertStringContainsString('src/A.php:3', $body);
        $this->assertStringContainsString('fixed in `abc`', $body);
        $this->assertStringContainsString('**pushed**', $body);
        $this->assertStringNotContainsString('is working on', $body);
    }

    /**
     * A proposed change that waits for a 👍 is called out in the summary with what to do; other results add no such line
     *
     * @return void
     */
    public function testSummarySaysWhatToDoWithAProposedChange(): void
    {
        $service = $this->service();
        $run     = $service->start('1', 3, [$this->thread('d1', 11), $this->thread('d2', 12), $this->thread('d3', 13)]);
        $service->finish('1', 3, $run, [
            'd1' => new ThreadResult(TriageStatus::Proposed, 'proposed a change to `src/A.php`', true),
            'd2' => new ThreadResult(TriageStatus::Proposed, 'proposed a change to `src/B.php`', true),
            'd3' => new ThreadResult(TriageStatus::Proposed, 'proposed leaving it: fine as is'),
        ]);
        $this->assertNotNull($run);
        $body = $this->summaryNote($run->noteId)['body'];
        $this->assertStringContainsString('**Next:** 2 proposed changes are waiting for you. React 👍', $body);
        $this->assertStringContainsString('Nothing is pushed without one.', $body);
        $this->assertStringEndsWith('-->', trim($body));

        $fresh = $this->service();
        $plain = $fresh->start('1', 3, [$this->thread('d4', 14)]);
        $fresh->finish('1', 3, $plain, ['d4' => new ThreadResult(TriageStatus::Pushed, 'fixed in `abc`')]);
        $this->assertNotNull($plain);
        $this->assertStringNotContainsString('**Next:**', $this->summaryNote($plain->noteId)['body']);
    }

    /**
     * Needs you wins over proposed, proposed over pushed
     *
     * @return void
     */
    public function testTheMostDemandingStatusWins(): void
    {
        $service = $this->service();
        $threads = [$this->thread('d1', 11), $this->thread('d2', 12), $this->thread('d3', 13)];
        $pushed  = new ThreadResult(TriageStatus::Pushed, 'a');
        $propose = new ThreadResult(TriageStatus::Proposed, 'b');
        $needs   = new ThreadResult(TriageStatus::NeedsYou, 'could not answer: yours — c');

        $service->finish('1', 3, $service->start('1', 3, $threads), ['d1' => $pushed, 'd2' => $propose]);
        $this->assertSame(['kelpie::proposed'], $this->gitlab->labels());

        $service->finish('1', 3, $service->start('1', 3, $threads), ['d1' => $pushed, 'd2' => $propose, 'd3' => $needs]);
        $this->assertSame(['kelpie::needs-you'], $this->gitlab->labels());

        $service->finish('1', 3, $service->start('1', 3, $threads), ['d1' => $pushed]);
        $this->assertSame(['kelpie::pushed'], $this->gitlab->labels());
    }

    /**
     * Whatever the run ends in, `working` and every other scoped label (old ones too) are gone: exactly one stays
     *
     * @return void
     */
    public function testEachTransitionLeavesExactlyOneScopedLabel(): void
    {
        $service  = $this->service();
        $outcomes = [TriageStatus::Pushed, TriageStatus::Proposed, TriageStatus::NeedsYou];

        foreach ($outcomes as $outcome) {
            $this->gitlab->withLabels(['bug']);
            $run = $service->start('1', 3, [$this->thread('d1', 11)]);
            $this->assertSame(['bug', 'kelpie::working'], $this->gitlab->labels());

            $this->gitlab->withLabels([...$this->gitlab->labels(), 'kelpie::needs-human', 'triage: proposed']);
            $service->finish('1', 3, $run, ['d1' => new ThreadResult($outcome, 'x')]);

            $this->assertSame(['bug', $outcome->value], $this->gitlab->labels());
        }
    }

    /**
     * A run that ends with no result clears `working` and leaves no outcome
     *
     * @return void
     */
    public function testNoResultLeavesNoScopedLabel(): void
    {
        $service = $this->service();
        $run     = $service->start('1', 3, [$this->thread('d1', 11)]);

        $this->assertNull($service->finish('1', 3, $run, []));
        $this->assertSame([], $this->gitlab->labels());
    }

    /**
     * A later round starts clean: the old outcome and the old claim go, other labels stay
     *
     * @return void
     */
    public function testALaterRoundResetsTheLabels(): void
    {
        $this->gitlab->withLabels(['bug', 'triage: needs you', 'kelpie::working', 'kelpie::needs-human', 'kelpie::pushed']);

        $this->service()->start('1', 3, [$this->thread('d1', 11)]);

        $this->assertSame(['bug', 'kelpie::working'], $this->gitlab->labels());
    }

    /**
     * With no result there is no outcome label to leave, and `working` is gone
     *
     * @return void
     */
    public function testNothingDoneLeavesNoOutcomeLabel(): void
    {
        $service = $this->service();
        $run     = $service->start('1', 3, [$this->thread('d1', 11)]);

        $this->assertNull($service->finish('1', 3, $run, []));
        $this->assertSame([], $this->gitlab->labels());
        $this->assertNotNull($run);
        $this->assertStringContainsString('left for now', $this->summaryNote($run->noteId)['body']);
    }

    /**
     * A run whose note could not be posted still ends with the right label
     *
     * @return void
     */
    public function testFinishWithoutANote(): void
    {
        $this->assertSame(TriageStatus::Proposed, $this->service()->finish('1', 3, null, ['d1' => new ThreadResult(TriageStatus::Proposed, 'x')]));
        $this->assertSame(['kelpie::proposed'], $this->gitlab->labels());
    }

    /**
     * What each verdict comes to
     *
     * @return void
     */
    public function testResultsByVerdict(): void
    {
        $service = $this->service();

        $needs = $service->resultFor(new TriageVerdict(TriageClass::NeedsHuman, 'Design call. @bob <!-- x -->'), ResponseOutcome::Replied);
        $this->assertSame(TriageStatus::NeedsYou, $needs->status);
        $this->assertStringContainsString('could not answer: yours — Design call.', $needs->text);
        $this->assertStringNotContainsString('<!--', $needs->text);
        $this->assertStringNotContainsString('@bob', $needs->text);

        $this->assertSame(TriageStatus::Pushed, $service->resultFor(new TriageVerdict(TriageClass::AlreadyFixed, 'Done.'), ResponseOutcome::Replied)->status);
        $this->assertSame(TriageStatus::Proposed, $service->resultFor(new TriageVerdict(TriageClass::NotAnIssue, 'Fine.'), ResponseOutcome::Replied)->status);
        $this->assertSame(TriageStatus::Proposed, $service->resultFor(new TriageVerdict(TriageClass::Fix, 'Typo.'), ResponseOutcome::Silent)->status);
        $this->assertSame('proposed deferring it: Later.', $service->resultFor(new TriageVerdict(TriageClass::Defer, 'Later.'), ResponseOutcome::Replied)->text);

        $context = $service->resultFor(new TriageVerdict(TriageClass::NeedsContext, 'Which file?'), ResponseOutcome::Replied);
        $this->assertSame(TriageStatus::NeedsYou, $context->status);
        $this->assertSame('asked for more context — Which file?', $context->text);

        $failed = $service->resultFor(new TriageVerdict(TriageClass::AlreadyFixed, 'Done.'), ResponseOutcome::Failed);
        $this->assertSame(TriageStatus::NeedsYou, $failed->status);
        $this->assertStringContainsString('reply failed', $failed->text);
    }

    /**
     * Off, draft and claimed are told apart
     *
     * @return void
     */
    public function testSkipAndClaimReasons(): void
    {
        $service = $this->service();

        $this->assertNull($service->skipReason($this->mr()));
        $this->assertFalse($service->isClaimed($this->mr()));
        $this->assertNotNull($service->skipReason($this->mr(labels: ['triage:off'])));
        $this->assertNotNull($service->skipReason($this->mr(draft: true)));
        $this->assertTrue($service->isClaimed($this->mr(labels: ['kelpie::working'])));
        $this->assertTrue($service->isClaimed($this->mr(labels: ['kelpie::working'])));
        $this->assertTrue($service->isClaimed($this->mr(labels: ['triage: working'])));
        $this->assertFalse($service->isClaimed($this->mr(labels: ['kelpie::pushed'])));
    }

    /**
     * Start every test with an empty GitLab
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab = new FakeGitlab();
    }

    /**
     * The stored summary note
     *
     * @param int $noteId The note ID
     *
     * @return array{body: string, id: int, author: string, system: bool, created_at: string}
     */
    private function summaryNote(int $noteId): array
    {
        foreach ($this->gitlab->notes() as $notes) {
            foreach ($notes as $note) {
                if ($note['id'] === $noteId) {
                    return $note;
                }
            }
        }

        $this->fail('No note ' . $noteId);
    }

    /**
     * @return TriageStatusService
     */
    private function service(): TriageStatusService
    {
        $logger = $this->createStub(Logger::class);

        return new TriageStatusService($logger, $this->gitlab->writer($logger));
    }

    /**
     * @param string $id     The discussion ID
     * @param int    $noteId The first note
     *
     * @return Thread
     */
    private function thread(string $id, int $noteId): Thread
    {
        return new Thread($id, $noteId, 'alice', 'Look.', false, 1, new DateTimeImmutable(), new DateTimeImmutable(), 'src/A.php', 3);
    }

    /**
     * @param bool     $draft  Whether it is a draft
     * @param string[] $labels Its labels
     *
     * @return MergeRequestInfo
     */
    private function mr(bool $draft = false, array $labels = []): MergeRequestInfo
    {
        return new MergeRequestInfo(str_repeat('a', 40), 'feat/x', 'dev', $draft, true, 1, 1, '', $labels);
    }
}
