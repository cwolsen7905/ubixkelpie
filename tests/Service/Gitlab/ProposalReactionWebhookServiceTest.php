<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Gitlab;

use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Service\Gitlab\MemberAccessReaderService;
use Kelpie\Service\Gitlab\ProposalReactionWebhookService;
use Kelpie\Service\Gitlab\ThreadActorService;
use Kelpie\Tests\Service\Autofix\FakeJobSql;
use Kelpie\Tests\Service\Autofix\InMemoryOutcomeStore;
use Kelpie\Tests\Service\Autofix\InsertedJobs;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Gitlab\ProposalReactionWebhookService
 *
 * What must hold:
 * - A thumbs-up from the MR author or a maintainer on a proposal with edits queues an apply job at the proposal's head
 * - A thumbs-down queues a decline job, and needs no edits
 * - Any other emoji, a reaction off a note or off an MR, and the bot's own reaction queue nothing
 * - A note that is not one of Kelpie's proposals, and a proposal no longer open, queue nothing
 * - A reactor who is neither the author nor a maintainer queues nothing
 * - Disabled, or a busy thread, or a cap, says so and queues nothing
 *
 * @coversDefaultClass \Kelpie\Service\Gitlab\ProposalReactionWebhookService
 */
final class ProposalReactionWebhookServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * The parameters of every job inserted
     */
    private InsertedJobs $inserts;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProposalReactionWebhookService::class);
    }

    /**
     * The author's thumbs-up queues an apply job for the thread at the head the proposal was made at
     *
     * @return void
     */
    public function testThumbsUpFromTheAuthorQueuesAnApply(): void
    {
        $result = $this->service()->handle($this->payload());

        $this->assertStringStartsWith('queued: apply g/p!3#d1', $result);
        $this->assertCount(1, $this->inserts);
        $this->assertSame('apply', $this->inserts->get(0)['source']);
        $this->assertSame('d1', $this->inserts->get(0)['discussion_id']);
        $this->assertSame(self::SHA, $this->inserts->get(0)['head_sha']);
    }

    /**
     * A maintainer who is not the author may apply too
     *
     * @return void
     */
    public function testThumbsUpFromAMaintainerQueuesAnApply(): void
    {
        $result = $this->service(level: 40)->handle($this->payload(userId: 6));

        $this->assertStringStartsWith('queued: apply', $result);
    }

    /**
     * A thumbs-down queues a decline, even on a proposal with no edits
     *
     * @return void
     */
    public function testThumbsDownQueuesADecline(): void
    {
        $result = $this->service(editsJson: '')->handle($this->payload(emoji: 'thumbsdown'));

        $this->assertStringStartsWith('queued: decline', $result);
        $this->assertSame('decline', $this->inserts->get(0)['source']);
    }

    /**
     * A thumbs-up on a proposal with no edits has nothing to apply
     *
     * @return void
     */
    public function testThumbsUpWithNoEditsQueuesNothing(): void
    {
        $this->assertStringContainsString('nothing to apply', $this->service(editsJson: '')->handle($this->payload()));
        $this->assertCount(0, $this->inserts);
    }

    /**
     * Other emoji, other awardables and a reaction off an MR queue nothing
     *
     * @return void
     */
    public function testWrongEmojiOrTargetQueuesNothing(): void
    {
        $service = $this->service();

        $this->assertStringContainsString('not a thumbs', $service->handle($this->payload(emoji: 'heart')));
        $this->assertStringContainsString('not on a note', $service->handle($this->payload(awardable: 'MergeRequest')));
        $this->assertStringContainsString('not on a merge request', $service->handle($this->payload(mrIid: 0)));
        $this->assertCount(0, $this->inserts);
    }

    /**
     * Kelpie's own reaction is never acted on
     *
     * @return void
     */
    public function testTheBotsOwnReactionIsIgnored(): void
    {
        $result = $this->service()->handle($this->payload(username: 'kelpie-bot'));

        $this->assertStringContainsString('bot\'s own reaction', $result);
        $this->assertCount(0, $this->inserts);
    }

    /**
     * A note that is not a proposal of Kelpie's is not acted on
     *
     * @return void
     */
    public function testANoteThatIsNotAProposalIsIgnored(): void
    {
        $result = $this->service()->handle($this->payload(noteId: 999));

        $this->assertStringContainsString('not a proposal of mine', $result);
        $this->assertCount(0, $this->inserts);
    }

    /**
     * A proposal already applied, declined or reopened is not acted on twice
     *
     * @return void
     */
    public function testAProposalThatIsNoLongerOpenIsIgnored(): void
    {
        $result = $this->service(outcome: ThreadOutcome::Applied)->handle($this->payload());

        $this->assertStringContainsString('already applied', $result);
        $this->assertCount(0, $this->inserts);
    }

    /**
     * A stranger, or a developer, cannot apply or decline
     *
     * @return void
     */
    public function testAnUnauthorisedReactorQueuesNothing(): void
    {
        $result = $this->service(level: 30)->handle($this->payload(userId: 6));

        $this->assertStringContainsString('not the MR author or a maintainer', $result);
        $this->assertCount(0, $this->inserts);

        $this->assertStringContainsString('not the MR author or a maintainer', $this->service(level: null)->handle($this->payload(userId: 6, emoji: 'thumbsdown')));
        $this->assertCount(0, $this->inserts);
    }

    /**
     * With autofix off nothing is queued
     *
     * @return void
     */
    public function testDisabledQueuesNothing(): void
    {
        $this->assertSame('skipped: autofix disabled', $this->service(enabled: false)->handle($this->payload()));
        $this->assertCount(0, $this->inserts);
    }

    /**
     * An open job on the thread covers it, so the reaction is not queued, and the person is told in the response
     *
     * @return void
     */
    public function testABusyThreadAndACapAreReported(): void
    {
        $this->assertStringContainsString('already queued', $this->service(mode: FakeJobSql::BUSY)->handle($this->payload()));
        $this->assertStringContainsString('cap reached', $this->service(mode: FakeJobSql::CAPPED)->handle($this->payload()));
        $this->assertCount(0, $this->inserts);
    }

    /**
     * @param ThreadOutcome $outcome   The stored outcome of the thread
     * @param string        $editsJson The stored edits
     * @param ?int          $level     The access level GitLab reports for the reactor
     * @param bool          $enabled   Whether autofix is on
     * @param string        $mode      What the job table does
     *
     * @return ProposalReactionWebhookService
     */
    private function service(ThreadOutcome $outcome = ThreadOutcome::Proposed, string $editsJson = '[{"file":"a.txt","new":"b","old":"a"}]', ?int $level = 10, bool $enabled = true, string $mode = FakeJobSql::FREE): ProposalReactionWebhookService
    {
        $logger        = new NullLogger();
        $this->inserts = new InsertedJobs();
        $store         = new InMemoryOutcomeStore();
        $store->record(new ThreadOutcomeRecord('g/p', 3, 'd1', self::SHA, 'fix', 'propose', $outcome, 'r', proposalNoteId: 105, editsJson: $editsJson));

        $members = new MemberAccessReaderService($logger, StubHttpClient::member($level), new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService($logger));
        $jobs    = FakeJobSql::service($logger, $this->createStub(SqlService::class), $this->inserts, $mode, self::SHA);

        return new ProposalReactionWebhookService($logger, new ThreadActorService($logger, $members), $store, $jobs, $enabled, 'kelpie-bot');
    }

    /**
     * An Emoji Hook for note 105 of g/p!3, reacted by user 5, who is also the MR's author
     *
     * @param string $emoji     The emoji
     * @param string $awardable The awardable type
     * @param int    $noteId    The note
     * @param int    $userId    The reactor
     * @param string $username  The reactor's username
     * @param int    $mrIid     The MR, 0 for a payload with none
     *
     * @return array<string, mixed>
     */
    private function payload(string $emoji = 'thumbsup', string $awardable = 'Note', int $noteId = 105, int $userId = 5, string $username = 'alice', int $mrIid = 3): array
    {
        return [
            'merge_request'     => $mrIid === 0 ? [] : ['author_id' => 5, 'iid' => $mrIid],
            'object_attributes' => ['awardable_id' => $noteId, 'awardable_type' => $awardable, 'name' => $emoji],
            'object_kind'       => 'emoji',
            'project'           => ['id' => 7, 'path_with_namespace' => 'g/p'],
            'user'              => ['id' => $userId, 'username' => $username],
        ];
    }
}
