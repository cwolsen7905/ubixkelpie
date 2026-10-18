<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Gitlab;

use Kelpie\Service\Gitlab\IssueAwardEmojiService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Gitlab\IssueAwardEmojiService
 *
 * What must hold:
 * - An issue with no 👀 from the bot gets one, through the award_emoji endpoint
 * - A 👀 from the bot already there means no POST; one from someone else does not count
 * - Any failure (network, status, JSON) is swallowed and nothing is thrown
 *
 * @coversDefaultClass \Kelpie\Service\Gitlab\IssueAwardEmojiService
 */
final class IssueAwardEmojiServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string LIST = 'GET /api/v4/projects/42/issues/7/award_emoji';
    private const string POST = 'POST /api/v4/projects/42/issues/7/award_emoji';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueAwardEmojiService::class);
    }

    /**
     * An issue without the bot's 👀 gets one
     *
     * @return void
     */
    public function testAnIssueWithoutEyesGetsOne(): void
    {
        $client = StubHttpClient::awards('[{"name":"thumbsup","user":{"username":"kelpie-bot"}}]');

        $this->assertTrue($this->service($client)->acknowledge(42, 7));
        $this->assertSame([self::LIST, self::POST], $client->requests());
    }

    /**
     * The request names the eyes emoji
     *
     * @return void
     */
    public function testThePostNamesTheEyesEmoji(): void
    {
        $client = StubHttpClient::awards('[]');

        $this->service($client)->acknowledge(42, 7);

        $this->assertSame(['GET per_page=100', 'POST name=eyes'], $client->queries());
    }

    /**
     * A 👀 from the bot already there means no second one
     *
     * @return void
     */
    public function testEyesFromTheBotAreNotDuplicated(): void
    {
        $client = StubHttpClient::awards('[{"name":"eyes","user":{"username":"kelpie-bot"}}]');

        $this->assertFalse($this->service($client)->acknowledge(42, 7));
        $this->assertSame([self::LIST], $client->requests());
    }

    /**
     * A 👀 from another user does not count as Kelpie's
     *
     * @return void
     */
    public function testEyesFromSomeoneElseDoNotCount(): void
    {
        $client = StubHttpClient::awards('[{"name":"eyes","user":{"username":"alice"}}]');

        $this->assertTrue($this->service($client)->acknowledge(42, 7));
        $this->assertSame([self::LIST, self::POST], $client->requests());
    }

    /**
     * GitLab's 404 for an award that exists is reported as nothing added
     *
     * @return void
     */
    public function testA404OnPostIsNotAnError(): void
    {
        $client = StubHttpClient::awards('[]', 404);

        $this->assertFalse($this->service($client)->acknowledge(42, 7));
    }

    /**
     * A network failure is swallowed
     *
     * @return void
     */
    public function testANetworkFailureIsSwallowed(): void
    {
        $client = new StubHttpClient(null);

        $this->assertFalse($this->service($client)->acknowledge(42, 7));
        $this->assertSame([self::LIST], $client->requests());
    }

    /**
     * A failed list posts nothing, whether by status or by JSON
     *
     * @return void
     */
    public function testAnUnreadableListPostsNothing(): void
    {
        foreach ([new Psr7Response(500, [], '{}'), new Psr7Response(200, [], 'not json')] as $answer) {
            $client = new StubHttpClient($answer);

            $this->assertFalse($this->service($client)->acknowledge(42, 7));
            $this->assertSame([self::LIST], $client->requests());
        }
    }

    /**
     * @param StubHttpClient $client The client
     *
     * @return IssueAwardEmojiService
     */
    private function service(StubHttpClient $client): IssueAwardEmojiService
    {
        return new IssueAwardEmojiService(new NullLogger(), $client, new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService(new NullLogger()), 'kelpie-bot');
    }
}
