<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use ArrayIterator;
use DateTimeImmutable;
use DateTimeZone;
use Kelpie\Service\Autofix\CapNoticeService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\CapNoticeService
 *
 * What must hold:
 * - A refusal for a daily job cap posts one plain MR note with the counts and the time it frees, and the label
 * - A second refusal in the same 24 hours posts nothing more
 * - After 24 hours it may post again
 * - Without GitLab settings, or when GitLab fails, nothing is posted and nothing is thrown
 * - A slow GitLab spends the notice's time budget: the label goes first, then the note; the retry reply likewise
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\CapNoticeService
 */
final class CapNoticeServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private FakeGitlab $gitlab;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(CapNoticeService::class);
    }

    /**
     * The first refusal posts the note and the label
     *
     * @return void
     */
    public function testPostsOneNoteAndTheLabel(): void
    {
        $now = new DateTimeImmutable('2026-11-20 10:00:00', new DateTimeZone('UTC'));

        $posted = $this->service()->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, $now->modify('+3 hours 5 minutes'), $now);

        $notes = array_merge(...array_values($this->gitlab->notes()));
        $this->assertTrue($posted);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString(CapNoticeService::MARKER, $notes[0]['body']);
        $this->assertStringContainsString('(30 of 30 in 24 h)', $notes[0]['body']);
        $this->assertStringContainsString('around 13:05 UTC', $notes[0]['body']);
        $this->assertContains(CapNoticeService::LABEL, $this->gitlab->labels());
    }

    /**
     * A passed soft cap leaves a warning note, with its own marker and label, that says the work keeps running
     *
     * @return void
     */
    public function testSoftCapNoteWarnsAndOnlyOncePerWindow(): void
    {
        $now     = new DateTimeImmutable('2026-11-20 10:00:00', new DateTimeZone('UTC'));
        $service = $this->service();

        $first  = $service->softCapExceeded('commits_per_mr', 'g/p', 3, 3, 3, $now);
        $second = $service->softCapExceeded('commits_per_mr', 'g/p', 3, 4, 3, $now);

        $notes = array_merge(...array_values($this->gitlab->notes()));
        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString(CapNoticeService::SOFT_MARKER, $notes[0]['body']);
        $this->assertStringContainsString('warning only', $notes[0]['body']);
        $this->assertContains(CapNoticeService::SOFT_LABEL, $this->gitlab->labels());
    }

    /**
     * The soft note and the hard note do not suppress each other
     *
     * @return void
     */
    public function testSoftAndHardNotesAreIndependent(): void
    {
        $now     = new DateTimeImmutable('2026-11-20 10:00:00', new DateTimeZone('UTC'));
        $service = $this->service();

        $this->assertTrue($service->softCapExceeded('jobs_per_mr', 'g/p', 3, 30, 30, $now));
        $this->assertTrue($service->jobsExceeded('jobs_per_mr', 'g/p', 3, 60, 60, $now->modify('+1 hour'), $now));
        $this->assertCount(2, array_merge(...array_values($this->gitlab->notes())));
    }

    /**
     * The project ceiling's note names the ceiling and says nothing in the project is triaged
     *
     * @return void
     */
    public function testProjectCeilingNoteNamesTheCeiling(): void
    {
        $this->service()->jobsExceeded('jobs_per_project', 'g/p', 3, 200, 200, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $notes = array_merge(...array_values($this->gitlab->notes()));
        $this->assertStringContainsString('project-wide daily job ceiling (200 of 200 in 24 h, across all MRs)', $notes[0]['body']);
        $this->assertStringContainsString('Nothing in this project is triaged', $notes[0]['body']);
    }

    /**
     * The MR cap's note names the MR
     *
     * @return void
     */
    public function testMrCapNoteNamesTheMr(): void
    {
        $this->service()->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $notes = array_merge(...array_values($this->gitlab->notes()));
        $this->assertStringContainsString('daily job cap for this MR (30 of 30 in 24 h)', $notes[0]['body']);
    }

    /**
     * A later refusal in the same window says nothing
     *
     * @return void
     */
    public function testNotPostedTwiceInAWindow(): void
    {
        $service = $this->service();
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->assertTrue($service->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, $now));
        $this->assertFalse($service->jobsExceeded('jobs_per_mr', 'g/p', 3, 31, 30, null, $now->modify('+2 hours')));
        $this->assertFalse($service->jobsExceeded('jobs_per_mr', 'g/p', 3, 31, 30, null, $now->modify('+23 hours')));

        $this->assertCount(1, array_merge(...array_values($this->gitlab->notes())));
    }

    /**
     * Refusals in quick succession look at GitLab once for the MR
     *
     * @return void
     */
    public function testRepeatedRefusalsCallGitlabOnce(): void
    {
        $service = $this->service();
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $service->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, $now);
        $service->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, $now->modify('+1 minute'));
        $service->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, $now->modify('+2 minutes'));

        $gets = array_filter($this->gitlab->requests(), static function (string $request): bool {
            return str_starts_with($request, 'GET ');
        });
        $this->assertCount(1, $gets);
    }

    /**
     * Past the window the note may come again
     *
     * @return void
     */
    public function testPostedAgainAfterTheWindow(): void
    {
        $service = $this->service();
        $this->gitlab->note('old', CapNoticeService::MARKER . "\nold", '-25 hours', null, 'kelpie-bot');

        $this->assertTrue($service->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
    }

    /**
     * Someone else's notes, even recent ones, do not count as having said it
     *
     * @return void
     */
    public function testOtherNotesDoNotSuppress(): void
    {
        $this->gitlab->note('d1', 'looks good', '-1 minutes');

        $this->assertTrue($this->service()->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
    }

    /**
     * A person quoting the marker does not count as the notice having been said
     *
     * @return void
     */
    public function testQuotedMarkerFromAPersonDoesNotSuppress(): void
    {
        $this->gitlab->note('d1', '> ' . CapNoticeService::MARKER . "\n> Kelpie is over its daily job cap", '-1 minutes', null, 'alice');

        $this->assertTrue($this->service()->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
    }

    /**
     * The retry reply and the MR note have their own markers: neither suppresses the other
     *
     * @return void
     */
    public function testRetryReplyAndMrNoteDoNotSuppressEachOther(): void
    {
        $service = $this->service();
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->gitlab->note('t1', 'Triage: retry', '-5 minutes');

        $this->assertTrue($service->retryNotQueued('g/p', 3, 't1', 'a reason'));
        $this->assertTrue($service->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, $now));
        $this->assertFalse($service->retryNotQueued('g/p', 3, 't1', 'a reason'));
    }

    /**
     * The notice queues no job: it makes no job-creating call and carries the marker the event filter ignores
     *
     * @return void
     */
    public function testNoLoop(): void
    {
        $this->service()->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $notes = array_merge(...array_values($this->gitlab->notes()));
        $this->assertSame('kelpie-bot', $notes[0]['author']);
        $this->assertStringStartsWith('<!-- kelpie:', $notes[0]['body']);
    }

    /**
     * No writer: nothing is posted
     *
     * @return void
     */
    public function testNothingWithoutGitlabSettings(): void
    {
        putenv('GITLAB_API_URL');
        putenv('KELPIE_BOT_GITLAB_TOKEN');
        $service = new CapNoticeService($this->createStub(Logger::class));

        $this->assertFalse($service->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, new DateTimeImmutable()));
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * A GitLab failure is not thrown and posts nothing
     *
     * @return void
     */
    public function testFailureIsSwallowed(): void
    {
        $this->gitlab->failNotes();

        $this->assertFalse($this->service()->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        $this->assertSame([], $this->gitlab->notes());
    }

    /**
     * A slow GitLab spends the budget: past it after the note, the label is skipped; past it after the read, the note too
     *
     * @return void
     */
    public function testSlowGitlabSpendsTheBudget(): void
    {
        $now = new DateTimeImmutable('2026-11-20 10:00:00', new DateTimeZone('UTC'));

        // Clock reads: start, before the note, before the label
        $this->assertTrue($this->service([0.0, 1.0, 4.5])->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, $now));
        $this->assertCount(1, array_merge(...array_values($this->gitlab->notes())));
        $this->assertNotContains(CapNoticeService::LABEL, $this->gitlab->labels());

        $this->setUp();
        $this->assertFalse($this->service([0.0, 4.0])->jobsExceeded('jobs_per_mr', 'g/p', 3, 30, 30, null, $now));
        $this->assertSame([], array_merge(...array_values($this->gitlab->notes())));
    }

    /**
     * The retry reply is skipped when reading the thread used up the budget
     *
     * @return void
     */
    public function testSlowGitlabSkipsTheRetryReply(): void
    {
        $this->gitlab->note('t1', 'Triage: retry', '-1 minute', null, 'bob');

        $this->assertFalse($this->service([0.0, 5.0])->retryNotQueued('g/p', 3, 't1', 'the job cap is reached'));
        $this->assertCount(1, $this->gitlab->notes()['t1']);
    }

    /**
     * Build the fake GitLab
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab = new FakeGitlab();
    }

    /**
     * @param float[] $clock The clock's readings in order, the last one repeating; empty for the real clock
     *
     * @return CapNoticeService
     */
    private function service(array $clock = []): CapNoticeService
    {
        $logger = $this->createStub(Logger::class);
        $reads  = new ArrayIterator(array_values($clock));
        $last   = $clock === [] ? 0.0 : (float) end($clock);

        return new CapNoticeService($logger, $this->gitlab->writer($logger), $clock === [] ? null : static function () use ($reads, $last): float {
            if (!$reads->valid()) {
                return $last;
            }
            $value = $reads->current();
            $reads->next();

            return $value;
        });
    }
}
