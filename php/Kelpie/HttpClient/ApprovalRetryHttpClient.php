<?php

declare(strict_types=1);

namespace Kelpie\HttpClient;

use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;

/**
 * The only way the approval-retry webhook talks to GitLab
 *
 * Kelpie must never approve a merge request or award a thumbs-up: a sign-off is a human's.
 * The API token the webhook holds could do both (retrying a job needs Developer and `api`),
 * so the token's scope is no protection. This client is: it is the HTTP client handed to
 * the uBixCore approval service, and it refuses, before anything reaches the network, every
 * request except
 *
 *  - GET  /projects/{id}/merge_requests/{iid}            (find the head pipeline)
 *  - GET  /projects/{id}/pipelines/{id}/jobs             (find the sign-off job)
 *  - POST /projects/{id}/jobs/{id}/retry | play          (re-run that one job)
 *
 * There is no path from the webhook to `/approve`, `/award_emoji`, `/pipeline` or `/merge`.
 *
 * @see \Kelpie\Tests\HttpClient\ApprovalRetryHttpClientTest PHPUnit test case
 */
final class ApprovalRetryHttpClient implements HttpClient
{
    /**
     * Allowed requests: method => path patterns, matched against the end of the path
     * (the GitLab API base, e.g. `/api/v4`, is deployment configuration)
     */
    private const ALLOWED = [
        'GET'  => [
            '#/projects/[^/]+/merge_requests/\d+$#',
            '#/projects/[^/]+/pipelines/\d+/jobs$#',
        ],
        'POST' => [
            '#/projects/[^/]+/jobs/\d+/(?:retry|play)$#',
        ],
    ];

    /**
     * Constructor
     *
     * @param Logger     $logger     Logger
     * @param HttpClient $httpClient The client that really talks to GitLab
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
    ) {
    }

    /**
     * Send the request if it is one of the allowed few
     *
     * @param Request $request The request
     *
     * @throws HttpClientException When the request is not an allowed one; nothing is sent
     *
     * @return Response The reply
     */
    public function sendRequest(Request $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path   = $request->getUri()->getPath();

        foreach (self::ALLOWED[$method] ?? [] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return $this->httpClient->sendRequest($request);
            }
        }

        $this->logger->warning('Refused a GitLab request outside the approval retry', ['method' => $method, 'path' => $path]);

        throw new HttpClientException(sprintf('Refused: Kelpie may not %s %s', $method, $path));
    }
}
