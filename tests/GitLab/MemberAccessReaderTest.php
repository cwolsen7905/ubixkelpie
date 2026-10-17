<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Kelpie\GitLab\MemberAccessReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\MemberAccessReader
 *
 * @coversDefaultClass \Kelpie\GitLab\MemberAccessReader
 */
final class MemberAccessReaderTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MemberAccessReader::class);
    }

    /**
     * The access level is read with one GET
     *
     * @return void
     */
    public function testReadsTheAccessLevel(): void
    {
        $client = StubHttpClient::member(30);

        $this->assertSame(30, $this->reader($client)->level(42, 5));
        $this->assertSame(['GET /api/v4/projects/42/members/all/5'], $client->requests());
    }

    /**
     * A non-member (404), bad JSON and a missing level all read as null
     *
     * @return void
     */
    public function testFailuresReadAsNull(): void
    {
        $this->assertNull($this->reader(StubHttpClient::member(null))->level(42, 5));
        $this->assertNull($this->reader(new StubHttpClient(new Psr7Response(200, [], 'not json')))->level(42, 5));
        $this->assertNull($this->reader(new StubHttpClient(new Psr7Response(200, [], '{"id":5}')))->level(42, 5));
    }

    /**
     * A client that throws reads as null
     *
     * @return void
     */
    public function testATransportErrorReadsAsNull(): void
    {
        $this->assertNull($this->reader(new StubHttpClient(null))->level(42, 5));
    }

    /**
     * @param StubHttpClient $client The client
     *
     * @return MemberAccessReader
     */
    private function reader(StubHttpClient $client): MemberAccessReader
    {
        return new MemberAccessReader(new NullLogger(), $client, new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService(new NullLogger()));
    }
}
