<?php

declare(strict_types=1);

namespace Kelpie\OpenRouter;

use Kelpie\DataTransferObject\Completion;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Throwable;
use Ubix\Service\JsonService;

/**
 * One chat-completion call to OpenRouter
 *
 * Generic on purpose (system + user message in, the model's text out); the triage nouns live in
 * Kelpie\Service\Triage. uBixCore has no LLM client yet (it ships a Gemini reviewer only), so this is the
 * piece to move upstream when a second consumer appears.
 *
 * Settings are read from the environment per call, where the uBixVault bootstrap puts them:
 * `OPENROUTER_API_KEY` (required), `OPENROUTER_API_URL` (optional).
 *
 * @see \Kelpie\Tests\OpenRouter\OpenRouterClientTest PHPUnit test case
 */
final class OpenRouterClient
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
     * Ask a model for a JSON-object answer
     *
     * Never throws: a missing key, a transport error, a non-200 or an unreadable body is logged
     * (never with the key) and yields null, so a caller can fall back to `needs_human`.
     *
     * @param string $model     Model id (e.g. `anthropic/claude-haiku-5.5`)
     * @param string $system    System message
     * @param string $user      User message
     * @param int    $maxTokens Upper bound on the answer's tokens
     *
     * @return ?string The model's text, or null on any failure
     */
    public function complete(string $model, string $system, string $user, int $maxTokens = 400): ?string
    {
        return $this->chat($model, $system, $user, $maxTokens)?->text;
    }

    /**
     * Ask a model for a JSON-object answer and report what it cost
     *
     * Same failure rules as `complete()`. The token counts and the cost are what OpenRouter's usage block
     * says; a provider that leaves them out gives 0 tokens and a null cost.
     *
     * @param string $model     Model id (e.g. `anthropic/claude-haiku-5.5`)
     * @param string $system    System message
     * @param string $user      User message
     * @param int    $maxTokens Upper bound on the answer's tokens
     *
     * @return ?Completion The answer, or null on any failure
     */
    public function chat(string $model, string $system, string $user, int $maxTokens = 400): ?Completion
    {
        $apiKey = (string) getenv('OPENROUTER_API_KEY');
        if ($apiKey === '') {
            $this->logger->error('OpenRouter: OPENROUTER_API_KEY is not set');

            return null;
        }

        $apiUrl = rtrim((string) getenv('OPENROUTER_API_URL') ?: self::DEFAULT_API_URL, '/');

        try {
            $payload = $this->jsonService->encode([
                'max_tokens'      => $maxTokens,
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'model'           => $model,
                'response_format' => ['type' => 'json_object'],
                'temperature'     => 0,
                'usage'           => ['include' => true],
            ]);

            $request  = $this->requestFactory->createRequest('POST', $apiUrl . '/chat/completions')
                ->withHeader('Authorization', 'Bearer ' . $apiKey)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));
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

        $choices = $decoded['choices'] ?? null;
        $first   = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($first) ? ($first['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (!is_string($content) || trim($content) === '') {
            $this->logger->error('OpenRouter: response had no message content', ['model' => $model]);

            return null;
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $cost  = $usage['cost'] ?? null;

        return new Completion(
            $content,
            $model,
            is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            is_int($cost) || is_float($cost) ? (float) $cost : null,
        );
    }
}
