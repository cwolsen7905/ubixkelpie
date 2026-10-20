<?php

declare(strict_types=1);

namespace Kelpie\OpenRouter\Review;

use Kelpie\DataTransferObject\Review\ChatTurn;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Throwable;
use Ubix\Service\JsonService;

/**
 * One chat-completion call to OpenRouter with tools, for the review's read-only agent loop
 *
 * `OpenRouterClient` answers one text for one prompt; the review needs tool calls and the usage of each call,
 * which it does not return. This stays beside it until a second consumer justifies one client upstream.
 * The key is read from `OPENROUTER_API_KEY` per call and is never logged. Never throws: any failure is null.
 *
 * @see \Kelpie\Tests\OpenRouter\Review\ReviewChatClientTest PHPUnit test case
 */
final class ReviewChatClient
{
    private const string DEFAULT_API_URL = 'https://openrouter.ai/api/v1';

    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param StreamFactory  $streamFactory  PSR-17 stream factory
     * @param JsonService    $jsonService    JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly JsonService $jsonService,
    ) {
    }

    /**
     * Ask the model for its next move
     *
     * @param string                     $model     The OpenRouter model id
     * @param string                     $effort    Reasoning effort, empty for the provider default
     * @param list<array<string, mixed>> $messages  The conversation so far
     * @param list<array<string, mixed>> $tools     The tools; empty to force a text answer
     * @param int                        $maxTokens Upper bound on the answer's tokens
     *
     * @return ?ChatTurn Null on any failure
     */
    public function chat(string $model, string $effort, array $messages, array $tools, int $maxTokens = 8000): ?ChatTurn
    {
        $apiKey = (string) getenv('OPENROUTER_API_KEY');
        if ($apiKey === '') {
            $this->logger->error('OpenRouter: OPENROUTER_API_KEY is not set');

            return null;
        }

        $apiUrl = rtrim((string) (getenv('OPENROUTER_API_URL') ?: self::DEFAULT_API_URL), '/');
        $body   = ['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => 0, 'usage' => ['include' => true]];
        if ($tools !== []) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }
        if ($effort !== '') {
            $body['reasoning'] = ['effort' => $effort];
        }

        try {
            $request  = $this->requestFactory->createRequest('POST', $apiUrl . '/chat/completions')
                ->withHeader('Authorization', 'Bearer ' . $apiKey)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->jsonService->encode($body)));
            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('OpenRouter: unexpected HTTP status', ['model' => $model, 'status' => $response->getStatusCode()]);

                return null;
            }

            $decoded = $this->jsonService->decode((string) $response->getBody());
        } catch (Throwable $e) {
            $this->logger->error('OpenRouter: request failed', ['model' => $model, 'error' => $e->getMessage()]);

            return null;
        }

        return $this->turn($decoded, $model);
    }

    /**
     * @param mixed $value A token count as the API wrote it
     *
     * @return int
     */
    private function count(mixed $value): int
    {
        return is_int($value) || is_float($value) ? (int) $value : 0;
    }

    /**
     * @param array<mixed> $decoded The decoded response
     * @param string       $model   The model, for the log
     *
     * @return ?ChatTurn
     */
    private function turn(array $decoded, string $model): ?ChatTurn
    {
        $first   = is_array($decoded['choices'] ?? null) ? ($decoded['choices'][0] ?? null) : null;
        $message = is_array($first) && is_array($first['message'] ?? null) ? $first['message'] : null;
        if ($message === null) {
            $this->logger->error('OpenRouter: response had no message', ['model' => $model]);

            return null;
        }

        $calls = [];
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $id       = is_array($call) ? ($call['id'] ?? null) : null;
            $function = is_array($call) && is_array($call['function'] ?? null) ? $call['function'] : [];
            if (is_string($id) && is_string($function['name'] ?? null)) {
                $calls[] = ['arguments' => is_string($function['arguments'] ?? null) ? $function['arguments'] : '{}', 'id' => $id, 'name' => $function['name']];
            }
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $cost  = $usage['cost'] ?? 0;

        return new ChatTurn(
            is_string($message['content'] ?? null) ? $message['content'] : '',
            $calls,
            $this->count($usage['prompt_tokens'] ?? 0),
            $this->count($usage['completion_tokens'] ?? 0),
            is_int($cost) || is_float($cost) ? (int) round($cost * 1000000) : 0,
        );
    }
}
