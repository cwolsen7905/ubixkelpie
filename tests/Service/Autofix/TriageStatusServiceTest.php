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
        $this->assertSame(['triage: working'], $this->gitlab->labels());
        $this->assertEqualsCanonicalizing(['triage: working', 'triage: pushed', 'triage: proposed', 'triage: needs you'], $this->gitlab->projectLabels());
        $this->assertSame(['11:eyes', '12:eyes'], $this->gitlab->awards());
        $this->assertStringContainsString('working on 2 threads', $this->gitlab->notes()[$run->discussionId][0]['body']);
        $this->assertSame($this->gitlab->notes()[$run->discussionId][0]['id'], $run->noteId);
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
        $this->assertSame(['triage: pushed'], $this->gitlab->labels());
        $this->assertNotNull($run);
        $this->assertCount(1, $this->gitlab->notes()[$run->discussionId]); // Edited, not a second note
        $body = $this->gitlab->notes()[$run->discussionId][0]['body'];
        $this->assertStringContainsString('src/A.php:3', $body);
        $this->assertStringContainsString('fixed in `abc`', $body);
        $this->assertStringContainsString('**pushed**', $body);
        $this->assertStringNotContainsString('is working on', $body);
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
        $this->assertSame(['triage: proposed'], $this->gitlab->labels());

        $service->finish('1', 3, $service->start('1', 3, $threads), ['d1' => $pushed, 'd2' => $propose, 'd3' => $needs]);
        $this->assertSame(['triage: needs you'], $this->gitlab->labels());

        $service->finish('1', 3, $service->start('1', 3, $threads), ['d1' => $pushed]);
        $this->assertSame(['triage: pushed'], $this->gitlab->labels());
    }

    /**
     * A later round starts clean: the old outcome and the old claim go, other labels stay
     *
     * @return void
     */
    public function testALaterRoundResetsTheLabels(): void
    {
        $this->gitlab->withLabels(['bug', 'triage: needs you', 'kelpie::working']);

        $this->service()->start('1', 3, [$this->thread('d1', 11)]);

        $this->assertSame(['bug', 'triage: working'], $this->gitlab->labels());
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
        $this->assertStringContainsString('left for now', $this->gitlab->notes()[$run->discussionId][0]['body']);
    }

    /**
     * A run whose note could not be posted still ends with the right label
     *
     * @return void
     */
    public function testFinishWithoutANote(): void
    {
        $this->assertSame(TriageStatus::Proposed, $this->service()->finish('1', 3, null, ['d1' => new ThreadResult(TriageStatus::Proposed, 'x')]));
        $this->assertSame(['triage: proposed'], $this->gitlab->labels());
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
        $this->assertTrue($service->isClaimed($this->mr(labels: ['triage: working'])));
        $this->assertTrue($service->isClaimed($this->mr(labels: ['kelpie::working'])));
        $this->assertFalse($service->isClaimed($this->mr(labels: ['triage: pushed'])));
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
