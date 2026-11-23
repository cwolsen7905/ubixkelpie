<?php

declare(strict_types=1);

namespace Kelpie\Tests\Security;

use Kelpie\Security\ApiTokenMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Security\ApiTokenMiddleware
 *
 * @coversDefaultClass \Kelpie\Security\ApiTokenMiddleware
 */
final class ApiTokenMiddlewareTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ApiTokenMiddleware::class);
    }

    /**
     * Test that the right token passes to the handler
     *
     * @return void
     */
    public function testRightTokenPasses(): void
    {
        $handler  = new ApiTokenRecordingHandler();
        $response = $this->send('s3cret', 'Bearer s3cret', $handler);

        $this->assertTrue($handler->wasCalled());
        $this->assertSame(204, $response->getStatusCode());
    }

    /**
     * Test that a missing, wrong, malformed or webhook-style credential is a 401 with a challenge and the handler never runs
     *
     * @return void
     */
    public function testBadCredentialsAre401(): void
    {
        foreach (['', 'Bearer wrong', 'Bearer', 'Basic s3cret', 's3cret', 'Bearer s3cret extra'] as $header) {
            $handler  = new ApiTokenRecordingHandler();
            $response = $this->send('s3cret', $header, $handler);

            $this->assertFalse($handler->wasCalled(), $header);
            $this->assertSame(401, $response->getStatusCode(), $header);
            $this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'), $header);
            $this->assertStringNotContainsString('s3cret', (string) $response->getBody());
        }
    }

    /**
     * Test that the GitLab webhook header does not authenticate, even with the right value
     *
     * @return void
     */
    public function testWebhookHeaderDoesNotAuthenticate(): void
    {
        $handler  = new ApiTokenRecordingHandler();
        $request  = new ServerRequest('GET', '/', ['X-Gitlab-Token' => 's3cret']);
        $response = (new ApiTokenMiddleware(new NullLogger(), new Psr17Factory(), new JsonService(new NullLogger()), 's3cret'))->process($request, $handler);

        $this->assertFalse($handler->wasCalled());
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test that with no token configured every request is a 503, even one that presents an empty token
     *
     * @return void
     */
    public function testNoTokenConfiguredFailsClosed(): void
    {
        foreach (['Bearer anything', 'Bearer ', ''] as $header) {
            $handler  = new ApiTokenRecordingHandler();
            $response = $this->send('', $header, $handler);

            $this->assertFalse($handler->wasCalled(), $header);
            $this->assertSame(503, $response->getStatusCode(), $header);
        }
    }

    /**
     * @param string                   $token   The configured token
     * @param string                   $header  The Authorization header
     * @param ApiTokenRecordingHandler $handler Records whether it ran
     *
     * @return Response
     */
    private function send(string $token, string $header, ApiTokenRecordingHandler $handler): Response
    {
        $request = new ServerRequest('GET', '/', $header === '' ? [] : ['Authorization' => $header]);

        return (new ApiTokenMiddleware(new NullLogger(), new Psr17Factory(), new JsonService(new NullLogger()), $token))->process($request, $handler);
    }
}
