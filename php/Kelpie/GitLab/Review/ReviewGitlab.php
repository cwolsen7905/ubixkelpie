<?php

declare(strict_types=1);

namespace Kelpie\GitLab\Review;

use Kelpie\DataTransferObject\Review\ReviewSettings;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Throwable;
use Ubix\Service\JsonService;

/**
 * The review's GitLab calls on one merge request, with the review's own token (`AI_REVIEW_GITLAB_TOKEN`)
 *
 * Not the Kelpie bot's client: the review runs in a pipeline of any repo and needs no Kelpie server or bot
 * account. Every failure is logged and answered with null/false, never thrown.
 *
 * @see \Kelpie\Tests\GitLab\Review\ReviewGitlabTest PHPUnit test case
 */
final class ReviewGitlab
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param StreamFactory  $streamFactory  PSR-17 stream factory
     * @param JsonService    $jsonService    JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly JsonService $jsonService,
    ) {
    }

    /**
     * Every discussion of the merge request, all pages
     *
     * @param ReviewSettings $settings The settings
     *
     * @return ?list<mixed> Null when a page could not be read
     */
    public function discussions(ReviewSettings $settings): ?array
    {
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            $chunk = $this->send($settings, 'GET', $this->mrUrl($settings) . '/discussions?per_page=100&page=' . $page, null);
            if ($chunk === null) {
                return null;
            }
            $all = [...$all, ...array_values($chunk)];
            if (count($chunk) < 100) {
                break;
            }
        }

        return $all;
    }

    /**
     * The user ID of the review's token, 0 when it could not be read
     *
     * @param ReviewSettings $settings The settings
     *
     * @return int
     */
    public function botId(ReviewSettings $settings): int
    {
        $id = $this->send($settings, 'GET', $settings->apiUrl . '/user', null)['id'] ?? 0;

        return is_int($id) ? $id : 0;
    }

    /**
     * The merge request's diff version, as the positions of inline threads need it
     *
     * @param ReviewSettings $settings The settings
     *
     * @return ?array{base_sha: string, start_sha: string, head_sha: string} Null when it could not be read
     */
    public function position(ReviewSettings $settings): ?array
    {
        $version = $this->send($settings, 'GET', $this->mrUrl($settings) . '/versions', null)[0] ?? null;
        if (!is_array($version)) {
            return null;
        }

        $base  = $version['base_commit_sha'] ?? null;
        $start = $version['start_commit_sha'] ?? null;
        $head  = $version['head_commit_sha'] ?? null;

        return is_string($base) && $base !== '' && is_string($start) && $start !== '' && is_string($head) && $head !== '' ? ['base_sha' => $base, 'start_sha' => $start, 'head_sha' => $head] : null;
    }

    /**
     * Post a plain note on the merge request
     *
     * @param ReviewSettings $settings The settings
     * @param string         $body     The note
     *
     * @return bool Whether GitLab accepted it
     */
    public function postNote(ReviewSettings $settings, string $body): bool
    {
        return $this->send($settings, 'POST', $this->mrUrl($settings) . '/notes', ['body' => $body]) !== null;
    }

    /**
     * Replace the body of a note
     *
     * @param ReviewSettings $settings The settings
     * @param int            $noteId   The note
     * @param string         $body     The new body
     *
     * @return bool Whether GitLab accepted it
     */
    public function editNote(ReviewSettings $settings, int $noteId, string $body): bool
    {
        return $this->send($settings, 'PUT', $this->mrUrl($settings) . '/notes/' . $noteId, ['body' => $body]) !== null;
    }

    /**
     * Open a thread, inline when GitLab takes the position and as a plain thread when it refuses it
     *
     * @param ReviewSettings                                                $settings The settings
     * @param string                                                        $body     The first note
     * @param string                                                        $file     The file
     * @param int                                                           $line     The line on the new side
     * @param ?array{base_sha: string, start_sha: string, head_sha: string} $position The diff version, null for a plain thread
     *
     * @return bool Whether a thread was opened
     */
    public function openThread(ReviewSettings $settings, string $body, string $file, int $line, ?array $position): bool
    {
        $url = $this->mrUrl($settings) . '/discussions';
        if ($position !== null && $line > 0) {
            $inline = ['body' => $body, 'position' => $position + ['position_type' => 'text', 'new_path' => $file, 'old_path' => $file, 'new_line' => $line]];
            if ($this->send($settings, 'POST', $url, $inline) !== null) {
                return true;
            }
            $this->logger->warning('Inline review thread refused, posting a plain thread', ['file' => $file, 'line' => $line]);
        }

        return $this->send($settings, 'POST', $url, ['body' => $body]) !== null;
    }

    /**
     * @param ReviewSettings $settings The settings
     *
     * @return string
     */
    private function mrUrl(ReviewSettings $settings): string
    {
        return sprintf('%s/projects/%s/merge_requests/%d', $settings->apiUrl, rawurlencode($settings->project), $settings->mrIid);
    }

    /**
     * @param ReviewSettings $settings The settings
     * @param string         $method   The HTTP method
     * @param string         $url      The URL
     * @param ?array<mixed>  $body     The JSON body, when there is one
     *
     * @return ?array<mixed> The decoded response, null on any failure
     */
    private function send(ReviewSettings $settings, string $method, string $url, ?array $body): ?array
    {
        try {
            $request = $this->requestFactory->createRequest($method, $url)->withHeader('PRIVATE-TOKEN', $settings->token);
            if ($body !== null) {
                $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streamFactory->createStream($this->jsonService->encode($body)));
            }
            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $this->logger->error('GitLab review call refused', ['method' => $method, 'status' => $response->getStatusCode(), 'url' => $url]);

                return null;
            }

            return $this->jsonService->decode((string) $response->getBody());
        } catch (Throwable $e) {
            $this->logger->error('GitLab review call failed', ['method' => $method, 'url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
