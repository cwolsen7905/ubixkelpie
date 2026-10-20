<?php

declare(strict_types=1);

namespace Kelpie\Tests\OpenRouter\Review;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;

/**
 * A model API that answers from a queue of replies and records what it was sent
 */
final class ScriptedModelHttp implements HttpClient
{
    /**
     * The decoded request bodies, in order
     *
     * @var list<array<mixed>>
     */
    private array $sent = [];

    /**
     * @var list<Response>
     */
    private array $queue = [];

    /**
     * The decoded request bodies, in order
     *
     * @return list<array<mixed>>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * Queue a reply that is the model's final text
     *
     * @param string $text   The text
     * @param int    $tokens Tokens in and out, each
     * @param float  $cost   The cost in dollars
     *
     * @return self
     */
    public function text(string $text, int $tokens = 100, float $cost = 0.001): self
    {
        return $this->queue(['content' => $text, 'role' => 'assistant'], $tokens, $cost);
    }

    /**
     * Queue a reply that calls one tool
     *
     * @param string       $name The tool
     * @param array<mixed> $args Its arguments
     *
     * @return self
     */
    public function tool(string $name, array $args): self
    {
        return $this->queue(['content' => null, 'role' => 'assistant', 'tool_calls' => [['function' => ['arguments' => $this->jsonService()->encode($args), 'name' => $name], 'id' => 'call_' . count($this->queue), 'type' => 'function']]], 10, 0.0001);
    }

    /**
     * Queue a failed reply
     *
     * @return self
     */
    public function fail(): self
    {
        $this->queue[] = new Psr7Response(500);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Response
    {
        $this->sent[] = $this->jsonService()->decode((string) $request->getBody());

        return array_shift($this->queue) ?? new Psr7Response(500);
    }

    /**
     * @param array<mixed> $message The assistant message
     * @param int          $tokens  Tokens in and out, each
     * @param float        $cost    The cost in dollars
     *
     * @return self
     */
    private function queue(array $message, int $tokens, float $cost): self
    {
        $this->queue[] = new Psr7Response(200, [], $this->jsonService()->encode(['choices' => [['message' => $message]], 'usage' => ['completion_tokens' => $tokens, 'cost' => $cost, 'prompt_tokens' => $tokens]]));

        return $this;
    }

    /**
     * @return JsonService
     */
    private function jsonService(): JsonService
    {
        return new JsonService(new NullLogger());
    }
}
