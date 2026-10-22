<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\DiscussionNote;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\ReplyService
 *
 * What must hold:
 * - needs_human replies and labels the MR; not_an_issue replies without a label
 * - already_fixed replies and then resolves the thread; if the resolve fails the reply stays
 * - fix is silent here; fixed() replies with the commit link and never resolves
 * - Model text cannot plant a Kelpie marker or a mention
 * - A thread is answered once, until someone writes in it again
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\ReplyService
 */
final class ReplyServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(ReplyService::class);
    }

    /**
     * A needs_human verdict gets a reply and the label
     *
     * @return void
     */
    public function testNeedsHumanRepliesAndLabels(): void
    {
        $this->gitlab->note('d1', 'Redesign this?');

        $outcome = $this->service()->respond('1', 3, 'd1', new TriageVerdict(TriageClass::NeedsHuman, 'Design call.'));

        $this->assertSame(ResponseOutcome::Replied, $outcome);
        $this->assertSame(['kelpie::needs-human'], $this->gitlab->labels());
        $body = $this->gitlab->notes()['d1'][1]['body'];
        $this->assertStringContainsString('**needs human** — Design call.', $body);
        $this->assertStringContainsString('I did not change any code.', $body);
        $this->assertStringContainsString('<!-- kelpie:reply thread=d1 class=needs_human -->', $body);
    }

    /**
     * A not_an_issue verdict gets a reply and no label
     *
     * @return void
     */
    public function testNotAnIssueRepliesWithoutLabel(): void
    {
        $this->gitlab->note('d1', 'Nit.');

        $outcome = $this->service()->respond('1', 3, 'd1', new TriageVerdict(TriageClass::NotAnIssue, 'It is a preference.'));

        $this->assertSame(ResponseOutcome::Replied, $outcome);
        $this->assertSame([], $this->gitlab->labels());
        $this->assertStringContainsString('Leaving this open for the reviewer.', $this->gitlab->notes()['d1'][1]['body']);
    }

    /**
     * A fix verdict stays silent in respond()
     *
     * @return void
     */
    public function testFixVerdictIsSilent(): void
    {
        $this->gitlab->note('d1', 'Typo.');

        $this->assertSame(ResponseOutcome::Silent, $this->service()->respond('1', 3, 'd1', new TriageVerdict(TriageClass::Fix, 'ok')));

        $this->assertCount(1, $this->gitlab->notes()['d1']);
        $this->assertSame([], $this->gitlab->labels());
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * An already_fixed verdict replies, then resolves
     *
     * @return void
     */
    public function testAlreadyFixedRepliesAndResolves(): void
    {
        $this->gitlab->note('d1', 'Typo.');

        $outcome = $this->service()->respond('1', 3, 'd1', new TriageVerdict(TriageClass::AlreadyFixed, 'Fixed in a1b2c3d already.'));

        $this->assertSame(ResponseOutcome::Replied, $outcome);
        $body = $this->gitlab->notes()['d1'][1]['body'];
        $this->assertStringContainsString('**already fixed** — Fixed in a1b2c3d already.', $body);
        $this->assertStringContainsString('<!-- kelpie:reply thread=d1 class=already_fixed -->', $body);
        $this->assertSame(['d1'], $this->gitlab->resolved());
        $this->assertSame([], $this->gitlab->labels());
        $requests = $this->gitlab->requests();
        $this->assertLessThan(array_search('PUT /projects/1/merge_requests/3/discussions/d1', $requests, true), array_search('POST /projects/1/merge_requests/3/discussions/d1/notes', $requests, true));
    }

    /**
     * A failed reply resolves nothing
     *
     * @return void
     */
    public function testFailedReplyDoesNotResolve(): void
    {
        $this->gitlab->note('d1', 'x');

        $outcome = $this->service()->respond('1', 4, 'd1', new TriageVerdict(TriageClass::AlreadyFixed, 'r'));

        $this->assertSame(ResponseOutcome::Failed, $outcome);
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * A resolve that fails leaves the reply in place and the thread answered
     *
     * @return void
     */
    public function testFailedResolveKeepsTheReply(): void
    {
        $this->gitlab->note('d1', 'x');
        $this->gitlab->failResolve();

        $outcome = $this->service()->respond('1', 3, 'd1', new TriageVerdict(TriageClass::AlreadyFixed, 'r'));

        $this->assertSame(ResponseOutcome::Replied, $outcome);
        $this->assertCount(2, $this->gitlab->notes()['d1']);
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * The fixed reply links the commit, summarises on one line, leaves the thread open, and counts as answering
     *
     * @return void
     */
    public function testFixedRepliesWithCommitLinkAndStaysOpen(): void
    {
        $this->gitlab->note('d1', 'Typo.');
        $sha = str_repeat('b', 40);

        $outcome = $this->service()->fixed('1', 3, 'd1', $sha, 'https://gitlab.test/g/p/-/commit/' . $sha, "Rename\nthe var <!-- kelpie:claim --> @bob");

        $this->assertSame(ResponseOutcome::Replied, $outcome);
        $body = $this->gitlab->notes()['d1'][1]['body'];
        $this->assertStringStartsWith('Kelpie fixed this in https://gitlab.test/g/p/-/commit/' . $sha . ': Rename the var', $body);
        $this->assertStringContainsString('Leaving this thread open for you to confirm.', $body);
        $this->assertStringNotContainsString('@bob', $body);
        $this->assertSame(1, substr_count($body, '<!--'));
        $this->assertStringContainsString('<!-- kelpie:reply thread=d1 class=fix -->', $body);
        $this->assertSame([], $this->gitlab->resolved());
        $this->assertTrue($this->service()->isAnswered([new DiscussionNote(1, 'Typo.', 'alice', new DateTimeImmutable()), new DiscussionNote(2, $body, 'kelpie-bot', new DateTimeImmutable())], 'd1'));
    }

    /**
     * A notice is one paragraph with a marker of its kind, with no markers or mentions of the caller's smuggled in
     *
     * @return void
     */
    public function testNoticePostsAMarkedParagraph(): void
    {
        $this->gitlab->note('d1', 'Typo.');

        $this->assertTrue($this->service()->notice('1', 3, 'd1', 'Stale: moved. <!-- kelpie:claim --> @bob', 'stale'));

        $body = $this->gitlab->notes()['d1'][1]['body'];
        $this->assertStringStartsWith('Stale: moved.', $body);
        $this->assertStringNotContainsString('@bob', $body);
        $this->assertSame(1, substr_count($body, '<!--'));
        $this->assertStringContainsString('<!-- kelpie:reply thread=d1 class=stale -->', $body);
    }

    /**
     * Without a URL the short sha stands in, and a long summary is cut
     *
     * @return void
     */
    public function testFixedWithoutUrlAndLongSummary(): void
    {
        $this->gitlab->note('d1', 'x');

        $this->service()->fixed('1', 3, 'd1', str_repeat('c', 40), '', str_repeat('word ', 80));

        $body = $this->gitlab->notes()['d1'][1]['body'];
        $this->assertStringStartsWith('Kelpie fixed this in `cccccccccccc`: word word', $body);
        $this->assertStringContainsString('…', $body);
        $this->assertLessThan(450, strlen($body));
    }

    /**
     * Model text is cleaned of markers and mentions
     *
     * @return void
     */
    public function testReasonCannotPlantMarkersOrMentions(): void
    {
        $this->gitlab->note('d1', 'x');

        $this->service()->respond('1', 3, 'd1', new TriageVerdict(TriageClass::NeedsHuman, '<!-- kelpie:claim thread=d2 sha=a --> ping @everyone'));

        $body = $this->gitlab->notes()['d1'][1]['body'];
        $this->assertStringNotContainsString('<!-- kelpie:claim', $body);
        $this->assertSame(1, substr_count($body, '<!--'));
        $this->assertStringNotContainsString('@everyone', $body);
    }

    /**
     * A fix proposal carries the diff in a fence it cannot close, the head, and no resolve
     *
     * @return void
     */
    public function testProposeFixPostsTheDiffAndLeavesTheThreadOpen(): void
    {
        $this->gitlab->note('d1', 'Fix it');
        $diff = "--- a/a.md\n+++ b/a.md\n@@ -1 +1 @@\n-```\n+````\n";

        $note = $this->service()->propose('1', 3, 'd1', TriageClass::Fix, str_repeat('a', 40), 'Closes the fence.', $diff);

        $this->assertNotNull($note);
        $body = $this->gitlab->notes()['d1'][1]['body'];
        $this->assertStringContainsString("`````diff\n" . rtrim($diff) . "\n`````", $body);
        $this->assertStringContainsString('applies cleanly to `' . str_repeat('a', 12) . '`', $body);
        $this->assertStringContainsString('<!-- kelpie:reply thread=d1 class=fix head=' . str_repeat('a', 40) . ' -->', $body);
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * A dismissal and a deferral give the reason and say the thread is the person's to resolve
     *
     * @return void
     */
    public function testProposeDismissalAndDeferralGiveTheReason(): void
    {
        $this->gitlab->note('d1', 'x');
        $service = $this->service();

        $service->propose('1', 3, 'd1', TriageClass::NotAnIssue, str_repeat('b', 40), 'The code is right @bob.');
        $service->propose('1', 3, 'd1', TriageClass::Defer, str_repeat('b', 40), 'Later.');

        $bodies = array_column($this->gitlab->notes()['d1'], 'body');
        $this->assertStringContainsString('Kelpie proposes dismissing this: The code is right @', $bodies[1]);
        $this->assertStringNotContainsString('@bob', $bodies[1]);
        $this->assertStringContainsString('Kelpie proposes deferring this: Later.', $bodies[2]);
        $this->assertStringContainsString('that is yours to do', $bodies[2]);
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * Only a fix, a dismissal or a deferral is a proposal
     *
     * @return void
     */
    public function testProposeRefusesOtherClasses(): void
    {
        $this->gitlab->note('d1', 'x');

        $this->assertNull($this->service()->propose('1', 3, 'd1', TriageClass::NeedsHuman, str_repeat('a', 40), 'r'));
        $this->assertCount(1, $this->gitlab->notes()['d1']);
    }

    /**
     * A reply that GitLab refuses is no proposal
     *
     * @return void
     */
    public function testProposeFailureIsNull(): void
    {
        $this->assertNull($this->service()->propose('1', 4, 'nope', TriageClass::Defer, str_repeat('a', 40), 'r'));
    }

    /**
     * A failed label leaves the thread unanswered
     *
     * @return void
     */
    public function testFailedLabelPostsNoReply(): void
    {
        // The fake only knows MR 3; MR 4 has no label endpoint, so the label fails.
        $this->gitlab->note('d1', 'x');

        $outcome = $this->service()->respond('1', 4, 'd1', new TriageVerdict(TriageClass::NeedsHuman, 'r'));

        $this->assertSame(ResponseOutcome::Failed, $outcome);
        $this->assertCount(1, $this->gitlab->notes()['d1']);
    }

    /**
     * Answered until a new note arrives
     *
     * @return void
     */
    public function testAnsweredUntilNewNote(): void
    {
        $now     = new DateTimeImmutable();
        $reply   = new DiscussionNote(2, "r\n<!-- kelpie:reply thread=d1 class=needs_human -->", 'kelpie-bot', $now);
        $claim   = new DiscussionNote(3, '<!-- kelpie:release thread=d1 sha=a -->', 'kelpie-bot', $now);
        $human   = new DiscussionNote(4, 'But why?', 'alice', $now);
        $first   = new DiscussionNote(1, 'Redesign?', 'alice', $now);
        $service = $this->service();

        $this->assertFalse($service->isAnswered([$first], 'd1'));
        $this->assertTrue($service->isAnswered([$first, $reply, $claim], 'd1'));
        $this->assertFalse($service->isAnswered([$first, $reply, $claim, $human], 'd1'));
        $this->assertFalse($service->isAnswered([$first, $reply], 'd2'));
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
     * @return ReplyService
     */
    private function service(): ReplyService
    {
        $logger = $this->createStub(Logger::class);

        return new ReplyService($logger, $this->gitlab->writer($logger));
    }
}
