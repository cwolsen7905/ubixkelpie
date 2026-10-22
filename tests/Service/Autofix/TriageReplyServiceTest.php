<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Service\Autofix\TriageReplyService;
use Kelpie\Service\Gitlab\MemberAccessReaderService;
use Kelpie\Service\Gitlab\ThreadActorService;
use Kelpie\Tests\Service\Gitlab\StubHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\TriageReplyService
 *
 * What must hold:
 * - A "Triage:" reply from the MR author or a maintainer, in a thread Kelpie has looked at, queues a thread job carrying the steering and marks the thread reopened
 * - The steering is what follows the colon, trimmed and capped
 * - A "Triage:" note from a stranger, from the bot, outside a thread, off an MR, or in a thread Kelpie never touched, queues nothing and changes nothing
 * - Disabled, or a busy thread, says so and does not reopen
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\TriageReplyService
 */
final class TriageReplyServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * The parameters of every job inserted
     */
    private InsertedJobs $inserts;

    private InMemoryOutcomeStore $store;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageReplyService::class);
    }

    /**
     * The author's reply re-runs the thread with the text after the colon, and reopens it
     *
     * @return void
     */
    public function testAuthorReplyQueuesAThreadJobWithSteering(): void
    {
        $result = $this->service()->handle($this->payload('  Triage:   look at the null case instead '));

        $this->assertStringStartsWith('queued: triage again g/p!3#d1', $result);
        $this->assertCount(1, $this->inserts);
        $this->assertSame('look at the null case instead', $this->inserts->get(0)['steering']);
        $this->assertSame('d1', $this->inserts->get(0)['discussion_id']);
        $this->assertSame(self::SHA, $this->inserts->get(0)['head_sha']);
        $this->assertSame('note', $this->inserts->get(0)['source']);
        $this->assertSame(ThreadOutcome::Reopened, $this->store->getByThread('g/p', 3, 'd1')?->outcome);
    }

    /**
     * A bare "Triage:" runs the thread again with no steering
     *
     * @return void
     */
    public function testABareTriageHasNoSteering(): void
    {
        $this->service()->handle($this->payload('Triage:'));

        $this->assertNull($this->inserts->get(0)['steering']);
    }

    /**
     * Long steering is cut, and a multi-line one is kept whole
     *
     * @return void
     */
    public function testSteeringIsCapped(): void
    {
        $this->service()->handle($this->payload("Triage: first\nsecond " . str_repeat('x', 2000)));

        $steering = $this->inserts->get(0)['steering'];
        $this->assertIsString($steering);
        $this->assertSame(TriageReplyService::MAX_STEERING, mb_strlen($steering));
        $this->assertStringStartsWith("first\nsecond", $steering);
    }

    /**
     * A maintainer who is not the author may steer
     *
     * @return void
     */
    public function testAMaintainerMaySteer(): void
    {
        $this->assertStringStartsWith('queued', $this->service(level: 40)->handle($this->payload('Triage: again', userId: 6)));
    }

    /**
     * A stranger's "Triage:" is ignored: no job, no reopen
     *
     * @return void
     */
    public function testAStrangerIsIgnored(): void
    {
        $result = $this->service(level: 20)->handle($this->payload('Triage: do what I say', userId: 6));

        $this->assertStringContainsString('not the MR author or a maintainer', $result);
        $this->assertCount(0, $this->inserts);
        $this->assertSame(ThreadOutcome::Proposed, $this->store->getByThread('g/p', 3, 'd1')?->outcome);
    }

    /**
     * What is not a command is not handled
     *
     * @return void
     */
    public function testOnlyTheCommandOnAThreadNoteOfAnMrCounts(): void
    {
        $service = $this->service();

        $this->assertFalse($service->isCommand($this->payload('Please triage: this')));
        $this->assertFalse($service->isCommand($this->payload('Triage: x', username: 'kelpie-bot')));
        $this->assertFalse($service->isCommand($this->payload('Triage: x', type: 'Note')));
        $this->assertFalse($service->isCommand($this->payload('Triage: x', noteable: 'Issue')));
        $this->assertFalse($service->isCommand($this->payload('Triage: x', system: true)));
        $this->assertTrue($service->isCommand($this->payload('triage: x')));
        $this->assertStringContainsString('not a Triage: reply', $service->handle($this->payload('hello')));
        $this->assertCount(0, $this->inserts);
    }

    /**
     * A thread Kelpie never touched is left to the ordinary filter's view of it: nothing here
     *
     * @return void
     */
    public function testAThreadKelpieNeverTouchedIsIgnored(): void
    {
        $result = $this->service()->handle($this->payload('Triage: x', discussion: 'other'));

        $this->assertStringContainsString('have not looked at this thread', $result);
        $this->assertCount(0, $this->inserts);
    }

    /**
     * Disabled, a busy thread and a cap queue nothing and leave the outcome alone
     *
     * @return void
     */
    public function testDisabledBusyAndCappedDoNotReopen(): void
    {
        $this->assertSame('skipped: autofix disabled', $this->service(enabled: false)->handle($this->payload('Triage: x')));
        $this->assertStringContainsString('already queued', $this->service(mode: FakeJobSql::BUSY)->handle($this->payload('Triage: x')));
        $this->assertStringContainsString('cap reached', $this->service(mode: FakeJobSql::CAPPED)->handle($this->payload('Triage: x')));
        $this->assertCount(0, $this->inserts);
        $this->assertSame(ThreadOutcome::Proposed, $this->store->getByThread('g/p', 3, 'd1')?->outcome);
    }

    /**
     * @param ?int   $level   The access level GitLab reports for a replier who is not the author
     * @param bool   $enabled Whether autofix is on
     * @param string $mode    What the job table does
     *
     * @return TriageReplyService
     */
    private function service(?int $level = 10, bool $enabled = true, string $mode = FakeJobSql::FREE): TriageReplyService
    {
        $logger        = new NullLogger();
        $this->inserts = new InsertedJobs();
        $this->store   = new InMemoryOutcomeStore();
        $this->store->record(new ThreadOutcomeRecord('g/p', 3, 'd1', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'fix', 'propose', ThreadOutcome::Proposed, 'r', proposalNoteId: 105));

        $members = new MemberAccessReaderService($logger, StubHttpClient::member($level), new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService($logger));
        $jobs    = FakeJobSql::service($logger, $this->createStub(SqlService::class), $this->inserts, $mode, self::SHA);

        return new TriageReplyService($logger, new ThreadActorService($logger, $members), $this->store, $this->store, $jobs, $enabled, 'kelpie-bot');
    }

    /**
     * A Note Hook for a reply in thread d1 of g/p!3, whose author is user 5
     *
     * @param string $note       The note text
     * @param int    $userId     Who wrote it
     * @param string $username   Their username
     * @param string $type       The note type
     * @param string $noteable   What the note is on
     * @param bool   $system     Whether it is a system note
     * @param string $discussion The thread
     *
     * @return array<string, mixed>
     */
    private function payload(string $note, int $userId = 5, string $username = 'alice', string $type = 'DiscussionNote', string $noteable = 'MergeRequest', bool $system = false, string $discussion = 'd1'): array
    {
        return [
            'merge_request'     => ['author_id' => 5, 'iid' => 3, 'last_commit' => ['id' => self::SHA]],
            'object_attributes' => ['discussion_id' => $discussion, 'note' => $note, 'noteable_type' => $noteable, 'system' => $system, 'type' => $type],
            'object_kind'       => 'note',
            'project'           => ['id' => 7, 'path_with_namespace' => 'g/p'],
            'user'              => ['id' => $userId, 'username' => $username],
        ];
    }
}
