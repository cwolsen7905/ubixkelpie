<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab\Review;

use Kelpie\GitLab\Review\ReviewGitlab;
use Kelpie\Tests\Service\Review\SettingsBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\Review\ReviewGitlab
 *
 * What must hold:
 * - Every request carries the review's token, and the paths are the merge request's
 * - Discussions are read page by page until a short page
 * - An inline thread carries the position; when GitLab refuses it a plain thread is posted
 * - A failure is null/false, never an exception
 *
 * @coversDefaultClass \Kelpie\GitLab\Review\ReviewGitlab
 */
final class ReviewGitlabTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewGitlab::class);
    }

    /**
     * Discussions are read across pages until one is short
     *
     * @return void
     */
    public function testDiscussionsPaginate(): void
    {
        $fake = new FakeReviewGitlab();
        $all  = [];
        for ($i = 0; $i < 130; $i++) {
            $all[] = ['id' => 'd' . $i, 'notes' => []];
        }
        $fake->withDiscussions($all);

        $got = $this->gitlab($fake)->discussions(SettingsBuilder::make());

        $this->assertNotNull($got);
        $this->assertCount(130, $got);
        $this->assertSame(['/api/v4/projects/1/merge_requests/3/discussions', '/api/v4/projects/1/merge_requests/3/discussions'], $fake->paths('GET'));
    }

    /**
     * A failed read is null
     *
     * @return void
     */
    public function testFailedDiscussionReadIsNull(): void
    {
        $fake = (new FakeReviewGitlab())->failReads();

        $this->assertNull($this->gitlab($fake)->discussions(SettingsBuilder::make()));
    }

    /**
     * The token's user, and 0 when it cannot be read
     *
     * @return void
     */
    public function testBotId(): void
    {
        $this->assertSame(9, $this->gitlab(new FakeReviewGitlab())->botId(SettingsBuilder::make()));
        $this->assertSame(0, $this->gitlab(new FakeReviewGitlab())->botId(SettingsBuilder::make(['token' => ''])));
    }

    /**
     * The diff version gives the position's three shas
     *
     * @return void
     */
    public function testPosition(): void
    {
        $this->assertSame(['base_sha' => 'b1', 'start_sha' => 's1', 'head_sha' => 'h1'], $this->gitlab(new FakeReviewGitlab())->position(SettingsBuilder::make()));
    }

    /**
     * A note is posted, and a note is replaced by its id
     *
     * @return void
     */
    public function testPostAndEditNote(): void
    {
        $fake   = new FakeReviewGitlab();
        $gitlab = $this->gitlab($fake);

        $this->assertTrue($gitlab->postNote(SettingsBuilder::make(), 'hello'));
        $this->assertTrue($gitlab->editNote(SettingsBuilder::make(), 17, 'again'));

        $this->assertSame(['hello', 'again'], $fake->noteBodies());
        $this->assertSame(['/api/v4/projects/1/merge_requests/3/notes'], $fake->paths('POST'));
        $this->assertSame(['/api/v4/projects/1/merge_requests/3/notes/17'], $fake->paths('PUT'));
    }

    /**
     * An inline thread carries the position
     *
     * @return void
     */
    public function testInlineThread(): void
    {
        $fake = new FakeReviewGitlab();

        $this->assertTrue($this->gitlab($fake)->openThread(SettingsBuilder::make(), 'body', 'src/A.php', 12, ['base_sha' => 'b1', 'start_sha' => 's1', 'head_sha' => 'h1']));

        $position = $fake->threads()[0]['position'];
        $this->assertIsArray($position);
        $this->assertSame(['text', 'src/A.php', 'src/A.php', 12, 'h1'], [$position['position_type'], $position['new_path'], $position['old_path'], $position['new_line'], $position['head_sha']]);
    }

    /**
     * A refused position falls back to a plain thread; with nothing accepted it is false
     *
     * @return void
     */
    public function testRefusedPositionFallsBackToAPlainThread(): void
    {
        $fake = (new FakeReviewGitlab())->refuseInline();

        $this->assertTrue($this->gitlab($fake)->openThread(SettingsBuilder::make(), 'body', 'src/A.php', 12, ['base_sha' => 'b1', 'start_sha' => 's1', 'head_sha' => 'h1']));
        $this->assertCount(2, $fake->threads());
        $this->assertArrayNotHasKey('position', $fake->threads()[1]);

        $broken = (new FakeReviewGitlab())->refuseWrites();
        $this->assertFalse($this->gitlab($broken)->openThread(SettingsBuilder::make(), 'body', 'src/A.php', 12, null));
    }

    /**
     * A transport exception is a failure, not an exception
     *
     * @return void
     */
    public function testTransportExceptionIsAFailure(): void
    {
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willThrowException(new HttpClientException('down'));
        $factory = new Psr17Factory();

        $gitlab = new ReviewGitlab($this->createStub(Logger::class), $http, $factory, $factory, new JsonService($this->createStub(Logger::class)));

        $this->assertNull($gitlab->discussions(SettingsBuilder::make()));
        $this->assertFalse($gitlab->postNote(SettingsBuilder::make(), 'x'));
    }

    /**
     * @param FakeReviewGitlab $fake The fake GitLab
     *
     * @return ReviewGitlab
     */
    private function gitlab(FakeReviewGitlab $fake): ReviewGitlab
    {
        $factory = new Psr17Factory();

        return new ReviewGitlab($this->createStub(Logger::class), $fake, $factory, $factory, new JsonService($this->createStub(Logger::class)));
    }
}
