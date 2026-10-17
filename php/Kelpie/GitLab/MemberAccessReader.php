<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Reads a user's access level in a project, inherited access included; GET only
 *
 * The level is looked up through the API by user ID, never taken from an event. Any failure
 * (network, status, shape) is logged and reported as null, so a caller can fail closed.
 *
 * @see \Kelpie\Tests\GitLab\MemberAccessReaderTest PHPUnit test case
 */
final class MemberAccessReader
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client, carrying the bot token
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
     * The user's access level in the project
     *
     * @param int $projectId The GitLab project ID
     * @param int $userId    The GitLab user ID
     *
     * @return ?int 10 Guest, 20 Reporter, 30 Developer, 40 Maintainer, 50 Owner; null when it could not be read
     */
    public function level(int $projectId, int $userId): ?int
    {
        $url = sprintf('%s/projects/%d/members/all/%d', $this->apiUrl, $projectId, $userId);

        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));
        } catch (ClientException $e) {
            $this->logger->error('GitLab member: request failed', ['error' => $e->getMessage(), 'project_id' => $projectId]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->warning('GitLab member: unexpected HTTP status', ['status' => $response->getStatusCode(), 'project_id' => $projectId, 'user_id' => $userId]);

            return null;
        }

        try {
            $member = $this->jsonService->decode((string) $response->getBody());
        } catch (DtoException $e) {
            $this->logger->error('GitLab member: JSON decode failed', ['error' => $e->getMessage()]);

            return null;
        }

        return is_int($member['access_level'] ?? null) ? $member['access_level'] : null;
    }
}
