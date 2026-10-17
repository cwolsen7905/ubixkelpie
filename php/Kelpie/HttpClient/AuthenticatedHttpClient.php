<?php

declare(strict_types=1);

namespace Kelpie\HttpClient;

use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;

/**
 * Adds a GitLab `PRIVATE-TOKEN` header to every request of a read-only client
 *
 * DiscussionReader sends bare requests; the dry-run command wraps its client in this so the
 * reader works against a private project. GET only: it refuses any other method.
 *
 * @see \Kelpie\Tests\HttpClient\AuthenticatedHttpClientTest PHPUnit test case
 */
final class AuthenticatedHttpClient implements HttpClient
{
    /**
     * Constructor
     *
     * @param Logger     $logger     Logger
     * @param HttpClient $httpClient The wrapped client
     * @param string     $token      GitLab API token
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly string $token,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @throws HttpClientException When the request is not a GET
     */
    public function sendRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'GET') {
            $this->logger->error('Authenticated client refused a non-GET request', ['method' => $request->getMethod()]);

            throw new HttpClientException('Only GET requests are allowed');
        }

        return $this->httpClient->sendRequest($request->withHeader('PRIVATE-TOKEN', $this->token));
    }
}
