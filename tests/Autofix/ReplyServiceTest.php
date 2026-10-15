<?php

declare(strict_types=1);

namespace Kelpie\Tests\Autofix;

use DateTimeImmutable;
use Kelpie\Autofix\ReplyService;
use Kelpie\DataTransferObject\DiscussionNote;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\Tests\GitLab\FakeGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Autofix\ReplyService
 *
 * What must hold:
 * - needs_human replies and labels the MR; not_an_issue replies without a label
 * - fix and already_fixed post nothing and label nothing
 * - Model text cannot plant a Kelpie marker or a mention
 * - A thread is answered once, until someone writes in it again
 *
 * @coversDefaultClass \Kelpie\Autofix\ReplyService
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
     * A fix or already_fixed verdict stays silent
     *
     * @return void
     */
    public function testFixAndAlreadyFixedAreSilent(): void
    {
        $this->gitlab->note('d1', 'Typo.');

        foreach ([TriageClass::Fix, TriageClass::AlreadyFixed] as $class) {
            $this->assertSame(ResponseOutcome::Silent, $this->service()->respond('1', 3, 'd1', new TriageVerdict($class, 'ok')));
        }

        $this->assertCount(1, $this->gitlab->notes()['d1']);
        $this->assertSame([], $this->gitlab->labels());
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
