<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;

/**
 * An OpenRouter that answers with the given texts in order, 500 once they run out, and keeps what it was sent
 */
final class ScriptedModelHttp implements HttpClient
{
    /**
     * The user message of every call
     *
     * @var list<string>
     */
    private array $sent = [];

    /**
     * Constructor
     *
     * @param string[] $answers The answers, one per call
     */
    public function __construct(
        private array $answers,
    ) {
    }

    /**
     * The user message of every call
     *
     * @return list<string>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Response
    {
        $json     = new JsonService(new NullLogger());
        $body     = $json->decode((string) $request->getBody());
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $user     = is_array($messages[1] ?? null) ? $messages[1] : [];

        $this->sent[] = is_string($user['content'] ?? null) ? $user['content'] : '';

        $answer = array_shift($this->answers);
        if ($answer === null) {
            return new Psr7Response(500);
        }

        return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $answer]]], 'usage' => ['completion_tokens' => 5, 'cost' => 0.01, 'prompt_tokens' => 50]]));
    }
}
