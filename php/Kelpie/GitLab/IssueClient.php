<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use Kelpie\DataTransferObject\IssueDetails;
use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Reads one GitLab issue and its notes, posts one comment and adds one label
 *
 * Give it the bot client (`BotHttpClient`: GET, POST and PUT only). A failed call is logged and
 * reported as null/false, never thrown, and issue text is never logged.
 *
 * @see \Kelpie\Tests\GitLab\IssueClientTest PHPUnit test case
 */
final class IssueClient
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The bot HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param StreamFactory  $streamFactory  PSR-17 stream factory
     * @param string         $apiUrl         GitLab API base URL (e.g. `https://gitlab.example.com/api/v4`)
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
     * Read the issue
     *
     * @param string $project The project path with namespace
     * @param int    $iid     The issue number in the project
     *
     * @return ?IssueDetails Null when the issue could not be read
     */
    public function issue(string $project, int $iid): ?IssueDetails
    {
        $data = $this->send('GET', $this->issueUrl($project, $iid), null);
        if ($data === null) {
            return null;
        }

        $author = is_array($data['author'] ?? null) ? ($data['author']['username'] ?? '') : '';
        $labels = [];
        foreach (is_array($data['labels'] ?? null) ? $data['labels'] : [] as $label) {
            if (is_string($label)) {
                $labels[] = $label;
            }
        }

        return new IssueDetails(
            title:        is_string($data['title'] ?? null) ? $data['title'] : '',
            description:  is_string($data['description'] ?? null) ? $data['description'] : '',
            author:       is_string($author) ? $author : '',
            confidential: ($data['confidential'] ?? false) === true,
            labels:       $labels,
        );
    }

    /**
     * Read the bodies of the issue's notes written by one user, oldest first
     *
     * @param string $project  The project path with namespace
     * @param int    $iid      The issue number in the project
     * @param string $username Only notes by this user are returned
     *
     * @return ?list<string> Null when the notes could not be read
     */
    public function notesBy(string $project, int $iid, string $username): ?array
    {
        $data = $this->send('GET', $this->issueUrl($project, $iid) . '/notes?sort=asc&order_by=created_at&per_page=100', null);
        if ($data === null) {
            return null;
        }

        $bodies = [];
        foreach ($data as $note) {
            $author = is_array($note) && is_array($note['author'] ?? null) ? ($note['author']['username'] ?? null) : null;
            if (is_array($note) && $author === $username && is_string($note['body'] ?? null)) {
                $bodies[] = $note['body'];
            }
        }

        return $bodies;
    }

    /**
     * Post a comment on the issue
     *
     * @param string $project The project path with namespace
     * @param int    $iid     The issue number in the project
     * @param string $body    The comment, Markdown
     *
     * @return bool Whether GitLab accepted it
     */
    public function comment(string $project, int $iid, string $body): bool
    {
        return $this->send('POST', $this->issueUrl($project, $iid) . '/notes', ['body' => $body]) !== null;
    }

    /**
     * Add a label to the issue, keeping the ones it has
     *
     * @param string $project The project path with namespace
     * @param int    $iid     The issue number in the project
     * @param string $label   The label title
     *
     * @return bool Whether GitLab accepted it
     */
    public function addLabel(string $project, int $iid, string $label): bool
    {
        return $this->send('PUT', $this->issueUrl($project, $iid), ['add_labels' => $label]) !== null;
    }

    /**
     * @param string $project The project path with namespace
     * @param int    $iid     The issue number
     *
     * @return string
     */
    private function issueUrl(string $project, int $iid): string
    {
        return sprintf('%s/projects/%s/issues/%d', $this->apiUrl, rawurlencode($project), $iid);
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
                $this->logger->error('GitLab issue: body is not encodable', ['error' => $e->getMessage()]);

                return null;
            }
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streamFactory->createStream($json));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientException $e) {
            $this->logger->error('GitLab issue: request failed', ['error' => $e->getMessage(), 'method' => $method, 'url' => $url]);

            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $this->logger->error('GitLab issue: unexpected HTTP status', ['method' => $method, 'status' => $response->getStatusCode(), 'url' => $url]);

            return null;
        }

        try {
            return $this->jsonService->decode((string) $response->getBody());
        } catch (DtoException $e) {
            $this->logger->error('GitLab issue: JSON decode failed', ['error' => $e->getMessage(), 'url' => $url]);

            return null;
        }
    }
}
