<?php

declare(strict_types=1);

namespace Kelpie\Tests\Triage;

use Kelpie\Triage\AuthenticatedHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Triage\AuthenticatedHttpClient
 *
 * @coversDefaultClass \Kelpie\Triage\AuthenticatedHttpClient
 */
final class AuthenticatedHttpClientTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AuthenticatedHttpClient::class);
    }

    /**
     * A GET carries the token header
     *
     * @return void
     */
    public function testGetCarriesToken(): void
    {
        $inner = $this->createMock(HttpClient::class);
        $inner->expects($this->once())->method('sendRequest')
            ->with($this->callback(static function (Request $request): bool {
                return $request->getHeaderLine('PRIVATE-TOKEN') === 'tok';
            }))
            ->willReturn(new Psr7Response(200));

        (new AuthenticatedHttpClient($this->createStub(Logger::class), $inner, 'tok'))->sendRequest((new Psr17Factory())->createRequest('GET', 'https://gitlab.test/x'));
    }

    /**
     * Anything but GET is refused before it reaches the network
     *
     * @return void
     */
    public function testNonGetIsRefused(): void
    {
        $inner = $this->createMock(HttpClient::class);
        $inner->expects($this->never())->method('sendRequest');

        $this->expectException(HttpClientException::class);

        (new AuthenticatedHttpClient($this->createStub(Logger::class), $inner, 'tok'))->sendRequest((new Psr17Factory())->createRequest('POST', 'https://gitlab.test/x'));
    }
}
