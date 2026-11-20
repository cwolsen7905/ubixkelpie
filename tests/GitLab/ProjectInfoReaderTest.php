<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use ArrayObject;
use Kelpie\GitLab\ProjectInfoReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\ProjectInfoReader
 *
 * What must hold:
 * - The path and visibility are read with one GET, the project URL-encoded
 * - An error status, an unreadable body or a missing field is null, never an exception
 *
 * @coversDefaultClass \Kelpie\GitLab\ProjectInfoReader
 */
final class ProjectInfoReaderTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectInfoReader::class);
    }

    /**
     * The path and visibility come back from one GET
     *
     * @return void
     */
    public function testReadsPathAndVisibility(): void
    {
        $seen = new ArrayObject();
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(static function (Request $request) use ($seen): Psr7Response {
            $seen->append($request->getMethod() . ' ' . $request->getUri()->getPath());

            return new Psr7Response(200, [], '{"path_with_namespace":"ubixsys/ubixvault","visibility":"public"}');
        });

        $info = $this->reader($http)->get('ubixsys/ubixvault');

        $this->assertNotNull($info);
        $this->assertSame('ubixsys/ubixvault', $info->path);
        $this->assertSame('public', $info->visibility);
        $this->assertSame(['GET /api/v4/projects/ubixsys%2Fubixvault'], $seen->getArrayCopy());
    }

    /**
     * Error status, bad JSON and a missing field are all null
     *
     * @return void
     */
    public function testFailuresAreNull(): void
    {
        foreach ([new Psr7Response(404, [], '{}'), new Psr7Response(200, [], 'not json'), new Psr7Response(200, [], '{"visibility":"public"}')] as $response) {
            $http = $this->createStub(HttpClient::class);
            $http->method('sendRequest')->willReturn($response);

            $this->assertNull($this->reader($http)->get('42'));
        }
    }

    /**
     * @param HttpClient $http The HTTP client
     *
     * @return ProjectInfoReader
     */
    private function reader(HttpClient $http): ProjectInfoReader
    {
        $logger = $this->createStub(Logger::class);

        return new ProjectInfoReader($logger, $http, new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService($logger));
    }
}
