<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Ubix\Enum\Exception\ExceptionCode;
use Ubix\Exception\HttpClientException;

/**
 * A gate pod for tests: answers with the given responses in order (null throws, as an unreachable pod does) and
 * keeps every request it was sent, body included
 */
final class FakeGateHttp implements HttpClient
{
    /**
     * Every request: method, URI, the steps header, content type, body
     *
     * @var list<array{method: string, uri: string, steps: string, type: string, body: string}>
     */
    private array $requests = [];

    /**
     * Constructor
     *
     * @param array<int, ?Psr7Response> $answers One per request; null throws; when they run out, every request throws
     */
    public function __construct(
        private array $answers,
    ) {
    }

    /**
     * A pod that answers once, 200 with this JSON
     *
     * @param string $json The body
     *
     * @return self
     */
    public static function answering(string $json): self
    {
        return new self([new Psr7Response(200, ['Content-Type' => 'application/json'], $json)]);
    }

    /**
     * The requests so far
     *
     * @return list<array{method: string, uri: string, steps: string, type: string, body: string}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * {@inheritDoc}
     *
     * @throws HttpClientException When the next answer is null, as an unreachable pod
     */
    public function sendRequest(Request $request): Response
    {
        $this->requests[] = [
            'body'   => (string) $request->getBody(),
            'method' => $request->getMethod(),
            'steps'  => $request->getHeaderLine('X-Kelpie-Gate-Steps'),
            'type'   => $request->getHeaderLine('Content-Type'),
            'uri'    => (string) $request->getUri(),
        ];

        $answer = array_shift($this->answers);
        if ($answer === null) {
            throw new HttpClientException('There was a cURL error (7: Failed to connect).', ExceptionCode::CURL_HTTP_CLIENT_ERROR->value);
        }

        return $answer;
    }
}
