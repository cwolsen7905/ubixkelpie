<?php

declare(strict_types=1);

namespace Kelpie\Tests\HttpClient;

use Kelpie\HttpClient\BotHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\HttpClient\BotHttpClient
 *
 * What must hold:
 * - GET, POST and PUT carry the token
 * - DELETE and PATCH are refused before they reach the network
 *
 * @coversDefaultClass \Kelpie\HttpClient\BotHttpClient
 */
final class BotHttpClientTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(BotHttpClient::class);
    }

    /**
     * The allowed methods carry the token
     *
     * @return void
     */
    public function testAllowedMethodsCarryToken(): void
    {
        $inner = $this->createMock(HttpClient::class);
        $inner->expects($this->exactly(3))->method('sendRequest')
            ->with($this->callback(static function (Request $request): bool {
                return $request->getHeaderLine('PRIVATE-TOKEN') === 'tok';
            }))
            ->willReturn(new Psr7Response(200));

        $client = new BotHttpClient($this->createStub(Logger::class), $inner, 'tok');
        foreach (['GET', 'POST', 'PUT'] as $method) {
            $client->sendRequest((new Psr17Factory())->createRequest($method, 'https://gitlab.test/x'));
        }
    }

    /**
     * Other methods are refused
     *
     * @return void
     */
    public function testDeleteIsRefused(): void
    {
        $inner = $this->createMock(HttpClient::class);
        $inner->expects($this->never())->method('sendRequest');

        $this->expectException(HttpClientException::class);

        (new BotHttpClient($this->createStub(Logger::class), $inner, 'tok'))->sendRequest((new Psr17Factory())->createRequest('DELETE', 'https://gitlab.test/x'));
    }
}
