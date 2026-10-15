<?php

declare(strict_types=1);

namespace Kelpie\HttpClient;

use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;

/**
 * Adds the kelpie-bot token to every request, and allows only GET, POST and PUT
 *
 * Replying, editing its own note and labelling an MR need no DELETE, so DELETE is not available,
 * whatever scope the token has.
 *
 * @see \Kelpie\Tests\HttpClient\BotHttpClientTest PHPUnit test case
 */
final class BotHttpClient implements HttpClient
{
    private const array METHODS = ['GET', 'POST', 'PUT'];

    /**
     * Constructor
     *
     * @param Logger     $logger     Logger
     * @param HttpClient $httpClient The wrapped client
     * @param string     $token      The `KELPIE_BOT_GITLAB_TOKEN` value
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
     * @throws HttpClientException When the method is not GET, POST or PUT
     */
    public function sendRequest(Request $request): Response
    {
        if (!in_array($request->getMethod(), self::METHODS, true)) {
            $this->logger->error('Bot client refused a request', ['method' => $request->getMethod()]);

            throw new HttpClientException('Only GET, POST and PUT requests are allowed');
        }

        return $this->httpClient->sendRequest($request->withHeader('PRIVATE-TOKEN', $this->token));
    }
}
