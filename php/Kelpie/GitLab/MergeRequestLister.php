<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use DateTimeImmutable;
use Exception;
use Kelpie\DataTransferObject\MergeRequestSummary;
use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Lists a project's open merge requests and reads a commit; GET only, no side effects
 *
 * A failed call is logged and reported as an empty list or null, never thrown.
 *
 * @see \Kelpie\Tests\GitLab\MergeRequestListerTest PHPUnit test case
 */
final class MergeRequestLister
{
    private const int PER_PAGE = 100;

    private const int MAX_PAGES = 10;

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
     * The open merge requests of a project
     *
     * @param string $projectPath The project path with namespace
     *
     * @return list<MergeRequestSummary>
     */
    public function open(string $projectPath): array
    {
        $found = [];
        $page  = 1;

        while ($page > 0 && $page <= self::MAX_PAGES) {
            $url      = sprintf('%s/projects/%s/merge_requests?state=opened&per_page=%d&page=%d', $this->apiUrl, rawurlencode($projectPath), self::PER_PAGE, $page);
            $response = $this->get($url);
            if ($response === null) {
                break;
            }

            try {
                $items = $this->jsonService->decode($response[0]);
            } catch (DtoException $e) {
                $this->logger->error('GitLab MR list: JSON decode failed', ['error' => $e->getMessage(), 'project' => $projectPath]);
                break;
            }

            foreach ($items as $item) {
                $summary = is_array($item) ? $this->toSummary($item) : null;
                if ($summary !== null) {
                    $found[] = $summary;
                }
            }

            $page = ctype_digit($response[1]) ? (int) $response[1] : 0;
        }

        return $found;
    }

    /**
     * Read a commit's message and time
     *
     * @param int    $projectId The GitLab project ID
     * @param string $sha       The commit sha
     *
     * @return ?array{message: string, committedAt: DateTimeImmutable} Null when it could not be read
     */
    public function commit(int $projectId, string $sha): ?array
    {
        $response = $this->get(sprintf('%s/projects/%d/repository/commits/%s', $this->apiUrl, $projectId, rawurlencode($sha)));
        if ($response === null) {
            return null;
        }

        try {
            $commit = $this->jsonService->decode($response[0]);
            $date   = $commit['committed_date'] ?? null;

            return is_string($commit['message'] ?? null) && is_string($date) ? ['message' => $commit['message'], 'committedAt' => new DateTimeImmutable($date)] : null;
        } catch (DtoException | Exception $e) {
            $this->logger->error('GitLab commit: could not be read', ['error' => $e->getMessage(), 'sha' => $sha]);

            return null;
        }
    }

    /**
     * @param string $url The URL
     *
     * @return ?array{0: string, 1: string} The body and the X-Next-Page header, null on any failure
     */
    private function get(string $url): ?array
    {
        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));
        } catch (ClientException $e) {
            $this->logger->error('GitLab read: request failed', ['error' => $e->getMessage(), 'url' => $url]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('GitLab read: unexpected HTTP status', ['status' => $response->getStatusCode(), 'url' => $url]);

            return null;
        }

        return [(string) $response->getBody(), $response->getHeaderLine('X-Next-Page')];
    }

    /**
     * @param array<mixed> $item One decoded MR
     *
     * @return ?MergeRequestSummary Null when it lacks a field the sweep needs
     */
    private function toSummary(array $item): ?MergeRequestSummary
    {
        $project = $item['project_id'] ?? null;
        $iid     = $item['iid'] ?? null;
        $sha     = $item['sha'] ?? null;
        $source  = $item['source_branch'] ?? null;
        $target  = $item['target_branch'] ?? null;

        if (!is_int($project) || !is_int($iid) || !is_string($sha) || $sha === '' || !is_string($source) || !is_string($target)) {
            return null;
        }

        $pipeline = is_array($item['head_pipeline'] ?? null) ? $item['head_pipeline'] : [];
        $user     = is_array($pipeline['user'] ?? null) ? ($pipeline['user']['username'] ?? null) : null;

        return new MergeRequestSummary(
            $project,
            $iid,
            is_string($item['title'] ?? null) ? $item['title'] : '',
            ($item['draft'] ?? false) === true,
            $source,
            $target,
            $sha,
            is_int($pipeline['id'] ?? null) ? $pipeline['id'] : null,
            is_string($pipeline['status'] ?? null) ? $pipeline['status'] : '',
            is_string($user) ? $user : '',
        );
    }
}
