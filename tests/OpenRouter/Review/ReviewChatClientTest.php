<?php

declare(strict_types=1);

namespace Kelpie\Tests\OpenRouter\Review;

use Kelpie\OpenRouter\Review\ReviewChatClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\OpenRouter\Review\ReviewChatClient
 *
 * What must hold:
 * - The request goes to chat/completions with a bearer key, the messages, the tools and the effort when one is set
 * - A text answer, or tool calls, and the tokens and cost (in micro-dollars) are read from the response
 * - Every failure (no key, HTTP error, bad body, no message, transport) is null, never an exception
 *
 * @coversDefaultClass \Kelpie\OpenRouter\Review\ReviewChatClient
 */
final class ReviewChatClientTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewChatClient::class);
    }

    /**
     * The request carries the key, model, messages, tools and effort
     *
     * @return void
     */
    public function testRequestShape(): void
    {
        $model = (new ScriptedModelHttp())->text('hi');

        $turn = $this->client($model)->chat('vendor/m', 'high', [['content' => 'u', 'role' => 'user']], [['type' => 'function']]);

        $this->assertNotNull($turn);
        $sent = $model->sent()[0];
        $this->assertSame('vendor/m', $sent['model']);
        $this->assertSame([['content' => 'u', 'role' => 'user']], $sent['messages']);
        $this->assertSame([['type' => 'function']], $sent['tools']);
        $this->assertSame('auto', $sent['tool_choice']);
        $this->assertSame(['effort' => 'high'], $sent['reasoning']);
        $this->assertSame(['include' => true], $sent['usage']);
    }

    /**
     * Without tools or effort neither is sent
     *
     * @return void
     */
    public function testNoToolsNoEffort(): void
    {
        $model = (new ScriptedModelHttp())->text('hi');

        $this->client($model)->chat('m', '', [], []);

        $this->assertArrayNotHasKey('tools', $model->sent()[0]);
        $this->assertArrayNotHasKey('reasoning', $model->sent()[0]);
    }

    /**
     * Text, tokens and cost are read
     *
     * @return void
     */
    public function testTextAnswerAndUsage(): void
    {
        $turn = $this->client((new ScriptedModelHttp())->text('the answer', 1234, 0.0123))->chat('m', '', [], []);

        $this->assertNotNull($turn);
        $this->assertSame(['the answer', [], 1234, 1234, 12300], [$turn->content, $turn->toolCalls, $turn->tokensIn, $turn->tokensOut, $turn->microCost]);
    }

    /**
     * Tool calls are read in order
     *
     * @return void
     */
    public function testToolCalls(): void
    {
        $turn = $this->client((new ScriptedModelHttp())->tool('read', ['path' => 'a.php']))->chat('m', '', [], []);

        $this->assertNotNull($turn);
        $this->assertSame('', $turn->content);
        $this->assertSame([['arguments' => '{"path":"a.php"}', 'id' => 'call_0', 'name' => 'read']], $turn->toolCalls);
    }

    /**
     * Every failure is null
     *
     * @return void
     */
    public function testFailuresAreNull(): void
    {
        $this->assertNull($this->client((new ScriptedModelHttp())->fail())->chat('m', '', [], []));

        $malformed = $this->createStub(HttpClient::class);
        $malformed->method('sendRequest')->willReturn(new Psr7Response(200, [], '{not json'));
        $this->assertNull($this->client($malformed)->chat('m', '', [], []));

        $empty = $this->createStub(HttpClient::class);
        $empty->method('sendRequest')->willReturn(new Psr7Response(200, [], '{"choices":[]}'));
        $this->assertNull($this->client($empty)->chat('m', '', [], []));

        $down = $this->createStub(HttpClient::class);
        $down->method('sendRequest')->willThrowException(new HttpClientException('down'));
        $this->assertNull($this->client($down)->chat('m', '', [], []));
    }

    /**
     * No key: null, and nothing is sent
     *
     * @return void
     */
    public function testMissingKeySendsNothing(): void
    {
        putenv('OPENROUTER_API_KEY');
        $model = (new ScriptedModelHttp())->text('hi');

        $this->assertNull($this->client($model)->chat('m', '', [], []));
        $this->assertSame([], $model->sent());
    }

    /**
     * Set a dummy key
     *
     * @return void
     */
    protected function setUp(): void
    {
        putenv('OPENROUTER_API_KEY=test-key-not-real');
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
    }

    /**
     * @param HttpClient $http The HTTP client
     *
     * @return ReviewChatClient
     */
    private function client(HttpClient $http): ReviewChatClient
    {
        $factory = new Psr17Factory();

        return new ReviewChatClient($this->createStub(Logger::class), $http, $factory, $factory, new JsonService($this->createStub(Logger::class)));
    }
}
