<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

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
 * - A refusal for the daily cap posts one plain MR note with the counts and the time it frees, and the label
 * - A second refusal in the same 24 hours posts nothing more
 * - After 24 hours it may post again
 * - Without GitLab settings, or when GitLab fails, nothing is posted and nothing is thrown
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

        $posted = $this->service()->jobsPerDayExceeded('g/p', 3, 100, 100, $now->modify('+3 hours 5 minutes'), $now);

        $notes = array_merge(...array_values($this->gitlab->notes()));
        $this->assertTrue($posted);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString(CapNoticeService::MARKER, $notes[0]['body']);
        $this->assertStringContainsString('(100 of 100 in 24 h)', $notes[0]['body']);
        $this->assertStringContainsString('around 13:05 UTC', $notes[0]['body']);
        $this->assertContains(CapNoticeService::LABEL, $this->gitlab->labels());
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

        $this->assertTrue($service->jobsPerDayExceeded('g/p', 3, 100, 100, null, $now));
        $this->assertFalse($service->jobsPerDayExceeded('g/p', 3, 101, 100, null, $now->modify('+2 hours')));
        $this->assertFalse($service->jobsPerDayExceeded('g/p', 3, 101, 100, null, $now->modify('+23 hours')));

        $this->assertCount(1, array_merge(...array_values($this->gitlab->notes())));
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

        $this->assertTrue($service->jobsPerDayExceeded('g/p', 3, 100, 100, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
    }

    /**
     * Someone else's notes, even recent ones, do not count as having said it
     *
     * @return void
     */
    public function testOtherNotesDoNotSuppress(): void
    {
        $this->gitlab->note('d1', 'looks good', '-1 minutes');

        $this->assertTrue($this->service()->jobsPerDayExceeded('g/p', 3, 100, 100, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
    }

    /**
     * The notice queues no job: it makes no job-creating call and carries the marker the event filter ignores
     *
     * @return void
     */
    public function testNoLoop(): void
    {
        $this->service()->jobsPerDayExceeded('g/p', 3, 100, 100, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));

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

        $this->assertFalse($service->jobsPerDayExceeded('g/p', 3, 100, 100, null, new DateTimeImmutable()));
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

        $this->assertFalse($this->service()->jobsPerDayExceeded('g/p', 3, 100, 100, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        $this->assertSame([], $this->gitlab->notes());
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
     * @return CapNoticeService
     */
    private function service(): CapNoticeService
    {
        $logger = $this->createStub(Logger::class);

        return new CapNoticeService($logger, $this->gitlab->writer($logger));
    }
}
