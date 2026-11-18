<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Answers every request with one canned response and keeps "METHOD url" and the body of each request
 */
final class RecordingHttp implements HttpClient
{
    /**
     * "METHOD url", then the body, of every request
     *
     * @var list<string>
     */
    private array $seen = [];

    /**
     * Constructor
     *
     * @param int    $status The status to answer
     * @param string $body   The body to answer
     */
    public function __construct(
        private readonly int $status,
        private readonly string $body,
    ) {
    }

    /**
     * @return list<string>
     */
    public function seen(): array
    {
        return $this->seen;
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Response
    {
        $this->seen[] = $request->getMethod() . ' ' . $request->getUri();
        $this->seen[] = (string) $request->getBody();

        return new Psr7Response($this->status, [], $this->body);
    }
}
