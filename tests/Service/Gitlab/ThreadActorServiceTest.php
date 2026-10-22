<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Gitlab;

use Kelpie\Service\Gitlab\MemberAccessReaderService;
use Kelpie\Service\Gitlab\ThreadActorService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Gitlab\ThreadActorService
 *
 * What must hold:
 * - The MR's author may, with no lookup
 * - Anyone else needs Maintainer access or more
 * - A level that cannot be read, no user and a developer all read as not allowed
 *
 * @coversDefaultClass \Kelpie\Service\Gitlab\ThreadActorService
 */
final class ThreadActorServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ThreadActorService::class);
    }

    /**
     * The author is allowed without asking GitLab
     *
     * @return void
     */
    public function testTheAuthorMay(): void
    {
        $client = StubHttpClient::member(10);

        $this->assertTrue($this->service($client)->may(7, 5, 5));
        $this->assertSame([], $client->requests());
    }

    /**
     * A maintainer or an owner may; a developer, a stranger and an unreadable level may not
     *
     * @return void
     */
    public function testOthersNeedMaintainerAccess(): void
    {
        $this->assertTrue($this->service(StubHttpClient::member(40))->may(7, 6, 5));
        $this->assertTrue($this->service(StubHttpClient::member(50))->may(7, 6, 5));
        $this->assertFalse($this->service(StubHttpClient::member(30))->may(7, 6, 5));
        $this->assertFalse($this->service(StubHttpClient::member(null))->may(7, 6, 5));
        $this->assertFalse($this->service(new StubHttpClient(null))->may(7, 6, 5));
    }

    /**
     * No user, or no author on record with a stranger, is not allowed
     *
     * @return void
     */
    public function testNoUserIsNotAllowed(): void
    {
        $this->assertFalse($this->service(StubHttpClient::member(50))->may(7, 0, 0));
        $this->assertFalse($this->service(StubHttpClient::member(30))->may(7, 6, 0));
    }

    /**
     * @param StubHttpClient $client The GitLab stand-in
     *
     * @return ThreadActorService
     */
    private function service(StubHttpClient $client): ThreadActorService
    {
        return new ThreadActorService(new NullLogger(), new MemberAccessReaderService(new NullLogger(), $client, new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService(new NullLogger())));
    }
}
