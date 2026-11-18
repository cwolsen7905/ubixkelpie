<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Opens a merge request from a branch Kelpie pushed, and finds out whether one is already open for it
 *
 * Give it the bot client (`BotHttpClient`: GET, POST and PUT only), so it can neither merge nor approve. A failed
 * call is logged and reported as null, never thrown, and merge request text is never logged.
 *
 * @see \Kelpie\Tests\GitLab\MergeRequestOpenerTest PHPUnit test case
 */
final class MergeRequestOpener
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The bot HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param StreamFactory  $streamFactory  PSR-17 stream factory
     * @param string         $apiUrl         GitLab API base URL
     * @param JsonService    $jsonService    JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly string $apiUrl,
        private readonly JsonService $jsonService,
    ) {
    }

    /**
     * The open merge requests that come from a source branch
     *
     * @param string $project The project path with namespace
     * @param string $branch  The source branch
     *
     * @return ?list<string> Their web URLs, empty when there is none; null when GitLab could not say
     */
    public function openFrom(string $project, string $branch): ?array
    {
        $data = $this->send('GET', sprintf('%s/projects/%s/merge_requests?state=opened&source_branch=%s', $this->apiUrl, rawurlencode($project), rawurlencode($branch)), null);
        if ($data === null) {
            return null;
        }

        $urls = [];
        foreach ($data as $item) {
            if (is_array($item) && is_string($item['web_url'] ?? null)) {
                $urls[] = $item['web_url'];
            }
        }

        return $urls;
    }

    /**
     * The commit a branch points at
     *
     * @param string $project The project path with namespace
     * @param string $branch  The branch
     *
     * @return ?string The 40-character sha; null when there is no such branch or GitLab could not say
     */
    public function branchSha(string $project, string $branch): ?string
    {
        $data   = $this->send('GET', sprintf('%s/projects/%s/repository/branches/%s', $this->apiUrl, rawurlencode($project), rawurlencode($branch)), null);
        $commit = is_array($data['commit'] ?? null) ? $data['commit'] : [];
        $sha    = $commit['id'] ?? null;

        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /**
     * The id of a user
     *
     * @param string $username The username
     *
     * @return ?int Null when there is no such user or GitLab could not say
     */
    public function userId(string $username): ?int
    {
        $data = $this->send('GET', sprintf('%s/users?username=%s', $this->apiUrl, rawurlencode($username)), null);
        $user = $data[0] ?? null;

        return is_array($user) && is_int($user['id'] ?? null) && ($user['username'] ?? null) === $username ? $user['id'] : null;
    }

    /**
     * Open a merge request
     *
     * @param string   $project     The project path with namespace
     * @param string   $branch      The source branch
     * @param string   $target      The target branch
     * @param string   $title       The title
     * @param string   $description The description, Markdown
     * @param string[] $labels      Label titles to set
     * @param int[]    $reviewerIds User ids to ask for a review
     *
     * @return ?string The merge request's web URL; null when it was not created
     */
    public function create(string $project, string $branch, string $target, string $title, string $description, array $labels, array $reviewerIds): ?string
    {
        $body = ['description' => $description, 'labels' => implode(',', $labels), 'remove_source_branch' => true, 'source_branch' => $branch, 'target_branch' => $target, 'title' => $title];
        if ($reviewerIds !== []) {
            $body['reviewer_ids'] = $reviewerIds;
        }

        $data = $this->send('POST', sprintf('%s/projects/%s/merge_requests', $this->apiUrl, rawurlencode($project)), $body);

        return is_string($data['web_url'] ?? null) ? $data['web_url'] : null;
    }

    /**
     * @param string                $method The HTTP method
     * @param string                $url    The URL
     * @param ?array<string, mixed> $body   A JSON body, or null for none
     *
     * @return ?array<mixed> The decoded response, null on any failure
     */
    private function send(string $method, string $url, ?array $body): ?array
    {
        $request = $this->requestFactory->createRequest($method, $url);
        if ($body !== null) {
            try {
                $json = $this->jsonService->encode($body);
            } catch (DtoException $e) {
                $this->logger->error('GitLab merge request: body is not encodable', ['error' => $e->getMessage()]);

                return null;
            }
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streamFactory->createStream($json));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientException $e) {
            $this->logger->error('GitLab merge request: request failed', ['error' => $e->getMessage(), 'method' => $method]);

            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $this->logger->error('GitLab merge request: unexpected HTTP status', ['method' => $method, 'status' => $response->getStatusCode()]);

            return null;
        }

        try {
            return $this->jsonService->decode((string) $response->getBody());
        } catch (DtoException $e) {
            $this->logger->error('GitLab merge request: JSON decode failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
