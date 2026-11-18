<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\Service\Autofix\SettledThreadService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\SettledThreadService
 *
 * What must hold:
 * - A fix reply naming a commit of the MR, or an agent's "fixed in <sha>", resolves the thread; a commit the MR does not carry, a
 *   bare thread, or a proposal does not
 * - A last reply "Dismissed:" (or "dismissed" in its first words) with a reason resolves; with no reason, or as a question, it does not
 * - A note from a person after the fix, or a fix that ends in a question, keeps the thread open
 * - A bot-written summary resolves once the threads it lists are resolved; one by anyone else, or one still working, does not
 * - Every resolve is logged with its reason; with `$apply` false nothing is written
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\SettledThreadService
 */
final class SettledThreadServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string SHA = 'abc1234def5678abc1234def5678abc1234def56';

    private FakeGitlab $gitlab;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SettledThreadService::class);
    }

    /**
     * Kelpie's push-mode reply and an agent's "fixed in" both resolve, once the commit is on the MR
     *
     * @return void
     */
    public function testFixedAndPushedResolves(): void
    {
        $this->gitlab->commitShas([self::SHA]);
        $this->gitlab->note('t1', 'Use a typed property.', '-2 hours', null, 'bob');
        $this->gitlab->note('t1', 'Fixed: [`abc1234def56`](https://x/-/commit/' . self::SHA . ") — typed it.\n\nResolving this thread.\n\n<!-- kelpie:reply thread=t1 class=fix -->", '-1 hour', null, 'kelpie-bot');
        $this->gitlab->note('t2', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t2', 'I fixed in abc1234 and renamed it.', '-1 hour', null, 'agent');

        $lines = $this->settle();

        $this->assertSame(['t1', 't2'], $this->gitlab->resolved());
        $this->assertSame('1!3 t1  resolved: fixed_pushed', $lines[0]);
    }

    /**
     * A commit the MR does not carry, a thread with no reply, and a proposal do not settle
     *
     * @return void
     */
    public function testFixNotOnTheMrDoesNotResolve(): void
    {
        $this->gitlab->commitShas([self::SHA]);
        $this->gitlab->note('t1', 'Use a typed property.', '-2 hours', null, 'bob');
        $this->gitlab->note('t1', 'Fixed: `fedcba987654` — typed it.', '-1 hour', null, 'kelpie-bot');
        $this->gitlab->note('t2', 'Fixed in fedcba9', '-1 hour', null, 'bob');
        $this->gitlab->note('t3', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t3', "Kelpie proposes this change, which was fixed in `abc1234def56`.\n\n<!-- kelpie:reply thread=t3 class=fix head=abc1234def5678 -->", '-1 hour', null, 'kelpie-bot');
        $this->gitlab->note('t4', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t4', 'The defaced effaced word is not a commit. Fixed.', '-1 hour', null, 'agent');

        $this->assertSame([], $this->settle());
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * A person's note after the fix, or a fix that asks a question, keeps the thread open
     *
     * @return void
     */
    public function testNewerHumanNoteOrQuestionKeepsItOpen(): void
    {
        $this->gitlab->commitShas([self::SHA]);
        $this->gitlab->note('t1', 'Use a typed property.', '-3 hours', null, 'bob');
        $this->gitlab->note('t1', 'Fixed: `abc1234def56` — typed it.', '-2 hours', null, 'kelpie-bot');
        $this->gitlab->note('t1', 'That broke the other call site too.', '-1 hour', null, 'bob');
        $this->gitlab->note('t2', 'Use a typed property?', '-3 hours', null, 'bob');
        $this->gitlab->note('t2', 'Is it fixed in abc1234?', '-1 hour', null, 'bob');
        $this->gitlab->note('t3', 'Use a typed property.', '-3 hours', null, 'bob');
        $this->gitlab->note('t3', 'Fixed: `abc1234def56` — typed it.', '-2 hours', null, 'kelpie-bot');
        $this->gitlab->note('t3', 'Why did you type it as int?', '-1 hour', null, 'bob');

        $this->assertSame([], $this->settle());
    }

    /**
     * The bot's own notes after the fix do not hold the thread open
     *
     * @return void
     */
    public function testBotNoteAfterTheFixIsFine(): void
    {
        $this->gitlab->commitShas([self::SHA]);
        $this->gitlab->note('t1', 'Use a typed property.', '-3 hours', null, 'bob');
        $this->gitlab->note('t1', 'Fixed: `abc1234def56` — typed it.', '-2 hours', null, 'agent');
        $this->gitlab->note('t1', 'Gate passed.', '-1 hour', null, 'kelpie-bot');

        $this->assertSame(['1!3 t1  resolved: fixed_pushed'], $this->settle());
    }

    /**
     * A last reply that dismisses and says why resolves; without a reason, negated, or as a question it does not
     *
     * @return void
     */
    public function testDismissedWithAReason(): void
    {
        $this->gitlab->note('t1', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t1', 'Dismissed: the name matches the interface it implements.', '-1 hour', null, 'bob');
        $this->gitlab->note('t2', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t2', 'Marking this dismissed because the legacy API needs that name.', '-1 hour', null, 'bob');
        $this->gitlab->note('t3', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t3', 'Dismissed.', '-1 hour', null, 'bob');
        $this->gitlab->note('t4', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t4', 'Dismissed: is that name really right for this class?', '-1 hour', null, 'bob');
        $this->gitlab->note('t5', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t5', 'This was not dismissed, the name still looks wrong to me here.', '-1 hour', null, 'bob');
        $this->gitlab->note('t6', 'Dismissed: not worth the churn for this module.', '-1 hour', null, 'bob');
        $this->gitlab->note('t7', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t7', "Kelpie proposes dismissing this: the name is fine here.\n\n<!-- kelpie:reply thread=t7 class=not_an_issue head=abc1234 -->", '-1 hour', null, 'kelpie-bot');
        $this->gitlab->note('t8', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t8', 'Dismissed: the name matches the interface it implements.', '-2 hours', null, 'bob');
        $this->gitlab->note('t8', 'Actually, no, it should change.', '-1 hour', null, 'bob');

        $this->settle();

        $this->assertSame(['t1', 't2'], $this->gitlab->resolved());
    }

    /**
     * Only the thread's author, the bot or a configured agent can settle it: a bystander's "dismissed" or "fixed in" is a comment
     *
     * @return void
     */
    public function testBystanderCannotSettleAThread(): void
    {
        $this->gitlab->commitShas([self::SHA]);
        $this->gitlab->note('t1', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t1', 'Dismissed: the name matches the interface it implements.', '-1 hour', null, 'mallory');
        $this->gitlab->note('t2', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t2', 'Fixed in abc1234, honest.', '-1 hour', null, 'mallory');

        $this->assertSame([], $this->settle());
        $this->assertSame([], $this->gitlab->resolved());
    }

    /**
     * A summary resolves once the threads it lists are resolved, not before
     *
     * @return void
     */
    public function testSummaryResolvesWhenItsThreadsAre(): void
    {
        $this->gitlab->note('aaaaaaaaaa11', 'Rename this.', '-3 hours', null, 'bob');
        $this->gitlab->note('aaaaaaaaaa11', 'Dismissed: the name matches the interface.', '-2 hours', null, 'bob');
        $this->gitlab->note('bbbbbbbbbb22', 'Do X.', '-3 hours', null, 'bob');
        $this->gitlab->note('sum', "Kelpie triage looked at 2 threads: **proposed**.\n\n<!-- kelpie:summary n=2 t1=aaaaaaaaaa.bbbbbbbbbb -->", '-1 hour', null, 'kelpie-bot');

        // The dismissed thread goes; the summary still waits for bbbbbbbbbb22
        $this->assertSame(['1!3 aaaaaaaaaa11  resolved: dismissed'], $this->settle());
        $this->assertSame(['aaaaaaaaaa11'], $this->gitlab->resolved());

        $this->gitlab->note('bbbbbbbbbb22', 'Dismissed: not worth the churn for this module.', '-1 minute', null, 'bob');
        $this->assertSame(['1!3 bbbbbbbbbb22  resolved: dismissed', '1!3 sum  resolved: summary_settled'], $this->settle());
    }

    /**
     * A summary without IDs waits for every other open thread; one still working is left
     *
     * @return void
     */
    public function testLegacyWorkingAndForgedSummaries(): void
    {
        $this->gitlab->note('old', "Kelpie triage looked at 1 thread.\n\n<!-- kelpie:summary -->", '-3 hours', null, 'kelpie-bot');
        $this->gitlab->note('busy', "Kelpie triage is working on 1 thread.\n\n<!-- kelpie:summary n=1 t1=cccccccccc -->", '-1 minute', null, 'kelpie-bot');
        $this->gitlab->note('real', 'Open question here.', '-3 hours', null, 'bob');

        $this->assertSame([], $this->settle());

        $this->gitlab->note('real', 'Dismissed: not worth the churn for this module.', '-1 minute', null, 'bob');
        $lines = $this->settle();
        $this->assertSame(['1!3 real  resolved: dismissed', '1!3 old  resolved: summary_settled'], $lines);
        $this->assertNotContains('busy', $this->gitlab->resolved());
    }

    /**
     * A summary marker typed by a person is not Kelpie's summary
     *
     * @return void
     */
    public function testForgedSummaryIsLeft(): void
    {
        $this->gitlab->note('fake', "Look!\n\n<!-- kelpie:summary n=0 -->", '-3 hours', null, 'mallory');

        $this->assertSame([], $this->settle());
    }

    /**
     * A "working" summary left behind for over an hour is cleared once its threads are done
     *
     * @return void
     */
    public function testStaleWorkingSummaryIsCleared(): void
    {
        $this->gitlab->note('busy', "Kelpie triage is working on 0 threads.\n\n<!-- kelpie:summary n=0 -->", '-3 hours', null, 'kelpie-bot');

        $this->assertSame(['1!3 busy  resolved: summary_settled'], $this->settle());
    }

    /**
     * Every resolve is logged with its reason, and a failed one is logged and not reported
     *
     * @return void
     */
    public function testLoggingAndFailure(): void
    {
        $this->gitlab->note('t1', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t1', 'Dismissed: the name matches the interface it implements.', '-1 hour', null, 'bob');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('info')->with('Settled thread resolved', ['discussion_id' => 't1', 'mr_iid' => 3, 'project_id' => 1, 'reason' => 'dismissed']);
        $this->assertSame(['1!3 t1  resolved: dismissed'], $this->service($logger)->settle(1, 3, new DateTimeImmutable(), true));

        $this->gitlab->note('t2', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t2', 'Dismissed: the name matches the interface it implements.', '-1 hour', null, 'bob');
        $this->gitlab->failResolve();
        $failing = $this->createMock(Logger::class);
        $failing->expects($this->once())->method('warning')->with('Settled thread could not be resolved');
        $this->assertSame([], $this->service($failing)->settle(1, 3, new DateTimeImmutable(), true));
    }

    /**
     * Without `$apply` nothing is written
     *
     * @return void
     */
    public function testReportOnly(): void
    {
        $this->gitlab->note('t1', 'Rename this.', '-2 hours', null, 'bob');
        $this->gitlab->note('t1', 'Dismissed: the name matches the interface it implements.', '-1 hour', null, 'bob');

        $this->assertSame(['1!3 t1  would resolve: dismissed'], $this->service($this->createStub(Logger::class))->settle(1, 3, new DateTimeImmutable(), false));
        $this->assertSame([], $this->gitlab->resolved());
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
     * @return list<string>
     */
    private function settle(): array
    {
        return $this->service($this->createStub(Logger::class))->settle(1, 3, new DateTimeImmutable(), true);
    }

    /**
     * @param Logger $logger Logger
     *
     * @return SettledThreadService
     */
    private function service(Logger $logger): SettledThreadService
    {
        return new SettledThreadService($logger, $this->gitlab->reader($logger), $this->gitlab->writer($logger), 'kelpie-bot', ['agent']);
    }
}
