<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use Kelpie\DataTransferObject\ProjectInfo;
use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Reads a project's path and visibility; GET only, no side effects
 *
 * A failed call is logged and reported as null, never thrown, so a caller that needs the answer to be
 * safe treats null as "not known to be public".
 *
 * @see \Kelpie\Tests\GitLab\ProjectInfoReaderTest PHPUnit test case
 */
final class ProjectInfoReader
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param string         $apiUrl         GitLab API base URL
     * @param JsonService    $jsonService    JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly string $apiUrl,
        private readonly JsonService $jsonService,
    ) {
    }

    /**
     * A project's path and visibility
     *
     * @param string $project The GitLab project ID or its path with namespace
     *
     * @return ?ProjectInfo Null when it could not be read
     */
    public function get(string $project): ?ProjectInfo
    {
        $url = sprintf('%s/projects/%s', $this->apiUrl, rawurlencode($project));

        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));
        } catch (ClientException $e) {
            $this->logger->error('GitLab project: request failed', ['error' => $e->getMessage(), 'project' => $project]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('GitLab project: unexpected HTTP status', ['status' => $response->getStatusCode(), 'project' => $project]);

            return null;
        }

        try {
            $item = $this->jsonService->decode((string) $response->getBody());
        } catch (DtoException $e) {
            $this->logger->error('GitLab project: JSON decode failed', ['error' => $e->getMessage(), 'project' => $project]);

            return null;
        }

        $path       = $item['path_with_namespace'] ?? null;
        $visibility = $item['visibility'] ?? null;

        return is_string($path) && $path !== '' && is_string($visibility) ? new ProjectInfo($path, $visibility) : null;
    }
}
