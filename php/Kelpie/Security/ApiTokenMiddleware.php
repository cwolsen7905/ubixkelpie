<?php

declare(strict_types=1);

namespace Kelpie\Security;

use Psr\Http\Message\ResponseFactoryInterface as ResponseFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Bearer-token gate for the settings API
 *
 * The token is the one secret `KELPIE_SETTINGS_API_TOKEN`, distinct from the GitLab webhook secret, so one leak opens
 * one door. Fails closed: with no token configured every request is a 503, never a pass. A missing or wrong token is a
 * 401 with `WWW-Authenticate: Bearer` and no hint as to which. The presented value is compared in constant time and is
 * never logged.
 *
 * uBixCore's BearerTokenAuthenticationMiddleware answers 403 for every failure and sends no challenge, which a client
 * cannot tell from "authenticated but not allowed"; the API contract is 401 and 503, hence this class.
 *
 * @see \Kelpie\Tests\Security\ApiTokenMiddlewareTest PHPUnit test case
 */
final class ApiTokenMiddleware implements Middleware
{
    /**
     * Constructor
     *
     * @param Logger          $logger          The Monolog logger
     * @param ResponseFactory $responseFactory Builds the 401 and 503 responses
     * @param JsonService     $jsonService     Encodes their body
     * @param string          $token           The accepted token; empty means the API is not configured
     */
    public function __construct(
        private Logger $logger,
        private ResponseFactory $responseFactory,
        private JsonService $jsonService,
        private string $token,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function process(Request $request, Handler $handler): Response
    {
        if ($this->token === '') {
            $this->logger->error('Settings API token is not configured; refusing the request');

            return $this->error(503, 'The settings API is not configured');
        }

        $presented = preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $matches) === 1 ? $matches[1] : '';

        if ($presented === '' || !hash_equals($this->token, $presented)) {
            $this->logger->notice('Settings API request with a missing or invalid bearer token');

            return $this->error(401, 'A valid bearer token is required')->withHeader('WWW-Authenticate', 'Bearer');
        }

        return $handler->handle($request);
    }

    /**
     * @param int    $status  HTTP status
     * @param string $message What to tell the caller
     *
     * @return Response
     */
    private function error(int $status, string $message): Response
    {
        $response = $this->responseFactory->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write($this->jsonService->encode(['error' => $message]));

        return $response;
    }
}
