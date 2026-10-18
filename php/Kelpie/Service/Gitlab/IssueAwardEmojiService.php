<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Marks an issue as seen with the bot's 👀 award emoji, once
 *
 * The issue's existing awards are read first, and nothing is posted when the bot already has an
 * eyes on it, so a redelivered event adds no duplicate. Every failure (network, status, shape) is
 * logged and swallowed: the acknowledgement is a courtesy and must never fail a webhook.
 *
 * @see \Kelpie\Tests\Service\Gitlab\IssueAwardEmojiServiceTest PHPUnit test case
 */
final class IssueAwardEmojiService
{
    /**
     * The GitLab name of the 👀 emoji
     */
    public const string EMOJI = 'eyes';

    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client, carrying the bot token
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param string         $apiUrl         GitLab API base URL
     * @param JsonService    $jsonService    JSON service
     * @param string         $botUsername    The Kelpie bot user, whose award counts as "already acknowledged"
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly string $apiUrl,
        private readonly JsonService $jsonService,
        private readonly string $botUsername = 'kelpie-bot',
    ) {
    }

    /**
     * Add the 👀 to the issue unless the bot already did
     *
     * @param int $projectId The GitLab project ID
     * @param int $issueIid  The issue number in the project
     *
     * @return bool Whether a new award was posted
     */
    public function acknowledge(int $projectId, int $issueIid): bool
    {
        $url = sprintf('%s/projects/%d/issues/%d/award_emoji', $this->apiUrl, $projectId, $issueIid);

        $awards = $this->existing($url, $projectId, $issueIid);
        if ($awards === null || $this->botHasAwarded($awards)) {
            return false;
        }

        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('POST', $url . '?name=' . self::EMOJI));
        } catch (ClientException $e) {
            $this->logger->error('GitLab award emoji: request failed', ['error' => $e->getMessage(), 'issue_iid' => $issueIid, 'project_id' => $projectId]);

            return false;
        }

        // 404 is what GitLab answers when the award exists already, which is the state we want.
        if ($response->getStatusCode() !== 201) {
            $this->logger->warning('GitLab award emoji: unexpected HTTP status', ['issue_iid' => $issueIid, 'project_id' => $projectId, 'status' => $response->getStatusCode()]);

            return false;
        }

        return true;
    }

    /**
     * The awards already on the issue
     *
     * @param string $url       The award_emoji URL
     * @param int    $projectId The GitLab project ID
     * @param int    $issueIid  The issue number
     *
     * @return ?array<int|string, mixed> The awards; null when they could not be read
     */
    private function existing(string $url, int $projectId, int $issueIid): ?array
    {
        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url . '?per_page=100'));
        } catch (ClientException $e) {
            $this->logger->error('GitLab award emoji: list failed', ['error' => $e->getMessage(), 'issue_iid' => $issueIid, 'project_id' => $projectId]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->warning('GitLab award emoji: unexpected HTTP status', ['issue_iid' => $issueIid, 'project_id' => $projectId, 'status' => $response->getStatusCode()]);

            return null;
        }

        try {
            return $this->jsonService->decode((string) $response->getBody());
        } catch (DtoException $e) {
            $this->logger->error('GitLab award emoji: JSON decode failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<int|string, mixed> $awards The issue's awards
     *
     * @return bool Whether one of them is the bot's eyes
     */
    private function botHasAwarded(array $awards): bool
    {
        foreach ($awards as $award) {
            $user = is_array($award) && is_array($award['user'] ?? null) ? $award['user'] : [];
            if (is_array($award) && ($award['name'] ?? null) === self::EMOJI && ($user['username'] ?? null) === $this->botUsername) {
                return true;
            }
        }

        return false;
    }
}
