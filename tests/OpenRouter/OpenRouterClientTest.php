<?php

declare(strict_types=1);

namespace Kelpie\Tests\OpenRouter;

use Kelpie\OpenRouter\OpenRouterClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\OpenRouter\OpenRouterClient
 *
 * What must hold:
 * - The request goes to chat/completions with a bearer key, the model and both messages
 * - The assistant's text is returned from a recorded response
 * - Every failure (no key, HTTP error, bad body, no content) is null, never an exception
 *
 * @coversDefaultClass \Kelpie\OpenRouter\OpenRouterClient
 */
final class OpenRouterClientTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * The request the stubbed client saw
     */
    private ?Request $sent = null;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(OpenRouterClient::class);
    }

    /**
     * The recorded response yields the assistant's text, and the request is shaped right
     *
     * @return void
     */
    public function testCompleteReturnsContentAndSendsExpectedRequest(): void
    {
        $body   = (string) file_get_contents(__DIR__ . '/fixtures/chat_completion_fix.json');
        $result = $this->client(new Psr7Response(200, [], $body))->complete('anthropic/claude-haiku-5.5', 'SYS', 'USR');

        $this->assertSame('{"class": "fix", "reason": "Rename the variable as asked."}', $result);
        $this->assertNotNull($this->sent);
        $this->assertSame('https://openrouter.test/api/v1/chat/completions', (string) $this->sent->getUri());
        $this->assertSame('Bearer test-key-not-real', $this->sent->getHeaderLine('Authorization'));

        $payload = (new JsonService($this->createStub(Logger::class)))->decode((string) $this->sent->getBody());
        $this->assertIsArray($payload['messages']);
        $this->assertSame('anthropic/claude-haiku-5.5', $payload['model']);
        $this->assertSame(['role' => 'system', 'content' => 'SYS'], $payload['messages'][0]);
        $this->assertSame(['role' => 'user', 'content' => 'USR'], $payload['messages'][1]);
    }

    /**
     * `chat()` reports the model, the token counts and the cost, and asks OpenRouter for usage
     *
     * @return void
     */
    public function testChatReportsUsageAndCost(): void
    {
        $body = '{"choices":[{"message":{"content":"{}"}}],"usage":{"prompt_tokens":210,"completion_tokens":18,"cost":0.0042}}';

        $completion = $this->client(new Psr7Response(200, [], $body))->chat('google/gemini-3-flash', 'S', 'U');

        $this->assertNotNull($completion);
        $this->assertSame('{}', $completion->text);
        $this->assertSame('google/gemini-3-flash', $completion->model);
        $this->assertSame(210, $completion->promptTokens);
        $this->assertSame(18, $completion->completionTokens);
        $this->assertSame(0.0042, $completion->costUsd);
        $this->assertNotNull($this->sent);
        $payload = (new JsonService($this->createStub(Logger::class)))->decode((string) $this->sent->getBody());
        $this->assertSame(['include' => true], $payload['usage']);
    }

    /**
     * A provider that leaves usage out gives zero tokens and no cost
     *
     * @return void
     */
    public function testChatWithoutUsageHasNoCost(): void
    {
        $completion = $this->client(new Psr7Response(200, [], '{"choices":[{"message":{"content":"{}"}}]}'))->chat('m', 'S', 'U');

        $this->assertNotNull($completion);
        $this->assertSame(0, $completion->promptTokens);
        $this->assertNull($completion->costUsd);
    }

    /**
     * No key: null, and nothing is sent
     *
     * @return void
     */
    public function testMissingKeyReturnsNullWithoutSending(): void
    {
        putenv('OPENROUTER_API_KEY');

        $this->assertNull($this->client(new Psr7Response(200))->complete('m', 's', 'u'));
        $this->assertNull($this->sent);
    }

    /**
     * An HTTP error, a malformed body and an empty answer are all null
     *
     * @return void
     */
    public function testFailuresReturnNull(): void
    {
        $this->assertNull($this->client(new Psr7Response(429, [], '{"error":"rate"}'))->complete('m', 's', 'u'));
        $this->assertNull($this->client(new Psr7Response(200, [], '{not json'))->complete('m', 's', 'u'));
        $this->assertNull($this->client(new Psr7Response(200, [], '{"choices":[]}'))->complete('m', 's', 'u'));
    }

    /**
     * A transport exception is null, not thrown
     *
     * @return void
     */
    public function testTransportExceptionReturnsNull(): void
    {
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willThrowException(new HttpClientException('down'));

        $client = new OpenRouterClient($this->createStub(Logger::class), $httpClient, new Psr17Factory(), new Psr17Factory(), new JsonService($this->createStub(Logger::class)));

        $this->assertNull($client->complete('m', 's', 'u'));
    }

    /**
     * Set a dummy key and a local URL
     *
     * @return void
     */
    protected function setUp(): void
    {
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1/');
        $this->sent = null;
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
        putenv('OPENROUTER_API_URL');
    }

    /**
     * Build a client that answers every request with the given response
     *
     * @param Psr7Response $response The canned response
     *
     * @return OpenRouterClient
     */
    private function client(Psr7Response $response): OpenRouterClient
    {
        $logger     = $this->createStub(Logger::class);
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willReturnCallback(function (Request $request) use ($response): Psr7Response {
            $this->sent = $request;

            return $response;
        });

        return new OpenRouterClient($logger, $httpClient, new Psr17Factory(), new Psr17Factory(), new JsonService($logger));
    }
}
