<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use ArrayObject;
use Kelpie\GitLab\FileReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\FileReader
 *
 * What must hold:
 * - One GET of the raw file at the commit, project and path URL-encoded
 * - An error status or a file over the size cap is null, never an exception
 *
 * @coversDefaultClass \Kelpie\GitLab\FileReader
 */
final class FileReaderTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FileReader::class);
    }

    /**
     * The file comes back from one GET of its raw text at the commit
     *
     * @return void
     */
    public function testReadsTheRawFileAtTheCommit(): void
    {
        $seen = new ArrayObject();
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(static function (Request $request) use ($seen): Psr7Response {
            $seen->append($request->getMethod() . ' ' . $request->getRequestTarget());

            return new Psr7Response(200, [], "line one\nline two\n");
        });

        $this->assertSame("line one\nline two\n", $this->reader($http)->read('ubixsys/shepherd', 'internal/store/a b.go', 'abc123'));
        $this->assertSame(['GET /api/v4/projects/ubixsys%2Fshepherd/repository/files/internal%2Fstore%2Fa%20b.go/raw?ref=abc123'], $seen->getArrayCopy());
    }

    /**
     * A missing file and one over the cap are null
     *
     * @return void
     */
    public function testFailuresAreNull(): void
    {
        foreach ([new Psr7Response(404, [], '{}'), new Psr7Response(200, [], str_repeat('x', FileReader::MAX_BYTES + 1))] as $response) {
            $http = $this->createStub(HttpClient::class);
            $http->method('sendRequest')->willReturn($response);
            $this->assertNull($this->reader($http)->read('42', 'a.go', 'abc'));
        }
    }

    /**
     * @param HttpClient $http The HTTP client
     *
     * @return FileReader
     */
    private function reader(HttpClient $http): FileReader
    {
        return new FileReader($this->createStub(Logger::class), $http, new Psr17Factory(), 'https://gitlab.example.com/api/v4');
    }
}
