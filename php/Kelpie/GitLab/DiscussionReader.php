<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use DateTimeImmutable;
use Kelpie\DataTransferObject\Thread;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Reads unresolved, resolvable threads from a GitLab MR's discussions
 *
 * No side effects: only reads. Does not reply, resolve, or write anything.
 *
 * @see \Kelpie\Tests\GitLab\DiscussionReaderTest PHPUnit test case
 */
final class DiscussionReader
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client (raw, not the guard)
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param string         $apiUrl         GitLab API base URL (e.g. `https://gitlab.example.com/api/v4`)
     * @param JsonService    $jsonService    JSON decode service
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
     * Return unresolved, resolvable threads for the given MR
     *
     * @param int $projectId The GitLab project ID
     * @param int $mrIid     The MR IID within that project
     *
     * @return list<Thread>
     */
    public function read(int $projectId, int $mrIid): array
    {
        $allDiscussions = [];
        $url            = sprintf('%s/projects/%d/merge_requests/%d/discussions', $this->apiUrl, $projectId, $mrIid);

        while ($url !== null) {
            $request  = $this->requestFactory->createRequest('GET', $url);
            $response = $this->httpClient->sendRequest($request);
            $body     = (string) $response->getBody();

            try {
                $page = $this->jsonService->decode($body);
            } catch (DtoException $e) {
                $this->logger->error('GitLab discussions: JSON decode failed', [
                    'error'      => $e->getMessage(),
                    'mr_iid'     => $mrIid,
                    'project_id' => $projectId,
                ]);
                break;
            }

            if (!is_array($page)) {
                $this->logger->error('GitLab discussions: unexpected response shape', [
                    'mr_iid'     => $mrIid,
                    'project_id' => $projectId,
                ]);
                break;
            }

            $allDiscussions = array_merge($allDiscussions, $page);

            $url        = null;
            $linkHeader = $response->getHeaderLine('X-Next-Page');

            if ($linkHeader !== '' && is_numeric($linkHeader)) {
                $url = sprintf('%s/projects/%d/merge_requests/%d/discussions', $this->apiUrl, $projectId, $mrIid);
            }
        }

        $threads = [];

        foreach ($allDiscussions as $discussion) {
            if (!is_array($discussion)) {
                continue;
            }

            if (isset($discussion['resolved']) && $discussion['resolved'] === true) {
                continue;
            }

            if (isset($discussion['resolvable']) && $discussion['resolvable'] !== true) {
                continue;
            }

            $notes     = is_array($discussion['notes'] ?? null) ? $discussion['notes'] : [];
            $firstNote = $notes[0] ?? null;
            $userNotes = [];

            foreach ($notes as $note) {
                if (!is_array($note)) {
                    continue;
                }

                if (isset($note['system']) && $note['system'] === true) {
                    continue;
                }
                $userNotes[] = $note;
            }

            if (empty($userNotes) && empty($notes)) {
                continue;
            }

            $author      = '';
            $authorArray = is_array($discussion['author'] ?? null) ? $discussion['author'] : [];
            if (is_string($authorArray['username'] ?? null)) {
                $author = $authorArray['username'];
            }

            $body = is_string($discussion['body'] ?? null) ? $discussion['body'] : '';

            $filePath = null;
            $line     = null;

            if ($firstNote !== null && is_array($firstNote)) {
                $position = is_array($firstNote['position'] ?? null) ? $firstNote['position'] : [];
                if (is_string($position['file_path'] ?? null)) {
                    $filePath = $position['file_path'];
                }
                if (is_int($position['line'] ?? null)) {
                    $line = $position['line'];
                }
            }

            $createdAt = new DateTimeImmutable();
            if (is_string($discussion['created_at'] ?? null)) {
                $createdAt = new DateTimeImmutable($discussion['created_at']);
            }

            $updatedAt = new DateTimeImmutable();
            if (is_string($discussion['updated_at'] ?? null)) {
                $updatedAt = new DateTimeImmutable($discussion['updated_at']);
            }

            $threadCount = is_int($discussion['thread_count'] ?? null) ? $discussion['thread_count'] : count($notes);

            $threads[] = new Thread(
                discussionId: is_int($discussion['id'] ?? null) ? $discussion['id'] : 0,
                firstNoteId:  $firstNote !== null && is_array($firstNote) && is_int($firstNote['id'] ?? null) ? $firstNote['id'] : 0,
                author:       $author,
                body:         $body,
                filePath:     $filePath,
                line:         $line,
                resolved:     false,
                createdAt:    $createdAt,
                updatedAt:    $updatedAt,
                noteCount:    $threadCount,
            );
        }

        return $threads;
    }
}
