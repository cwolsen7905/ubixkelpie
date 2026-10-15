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
     * Discussions requested per page (the GitLab maximum)
     */
    private const int PER_PAGE = 100;

    /**
     * Upper bound on pages followed, so a misbehaving server cannot loop us forever
     */
    private const int MAX_PAGES = 100;

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
     * A thread is kept when at least one of its human notes is resolvable and none is resolved.
     * System notes are ignored; a discussion made only of them is skipped.
     *
     * @param int $projectId The GitLab project ID
     * @param int $mrIid     The MR IID within that project
     *
     * @return list<Thread>
     */
    public function read(int $projectId, int $mrIid): array
    {
        $threads = [];
        $page    = 1;

        while ($page > 0 && $page <= self::MAX_PAGES) {
            $url      = sprintf(
                '%s/projects/%d/merge_requests/%d/discussions?per_page=%d&page=%d',
                $this->apiUrl,
                $projectId,
                $mrIid,
                self::PER_PAGE,
                $page,
            );
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('GitLab discussions: unexpected HTTP status', [
                    'mr_iid'     => $mrIid,
                    'page'       => $page,
                    'project_id' => $projectId,
                    'status'     => $response->getStatusCode(),
                ]);
                break;
            }

            try {
                $discussions = $this->jsonService->decode((string) $response->getBody());
            } catch (DtoException $e) {
                $this->logger->error('GitLab discussions: JSON decode failed', [
                    'error'      => $e->getMessage(),
                    'mr_iid'     => $mrIid,
                    'page'       => $page,
                    'project_id' => $projectId,
                ]);
                break;
            }

            foreach ($discussions as $discussion) {
                $thread = is_array($discussion) ? $this->toThread($discussion) : null;
                if ($thread !== null) {
                    $threads[] = $thread;
                }
            }

            $next = $response->getHeaderLine('X-Next-Page');
            $page = ctype_digit($next) ? (int) $next : 0;
        }

        return $threads;
    }

    /**
     * Build a Thread from one discussion, or null when it is not an open, resolvable thread
     *
     * @param array<mixed> $discussion One decoded discussion
     *
     * @return ?Thread
     */
    private function toThread(array $discussion): ?Thread
    {
        $id    = $discussion['id'] ?? null;
        $notes = is_array($discussion['notes'] ?? null) ? $discussion['notes'] : [];

        $humanNotes = [];
        foreach ($notes as $note) {
            if (is_array($note) && ($note['system'] ?? false) !== true) {
                $humanNotes[] = $note;
            }
        }

        $first = $humanNotes[0] ?? null;
        if (!is_string($id) || $first === null) {
            return null;
        }

        $resolvable = false;
        foreach ($humanNotes as $note) {
            if (($note['resolved'] ?? false) === true) {
                return null;
            }
            if (($note['resolvable'] ?? false) === true) {
                $resolvable = true;
            }
        }

        $noteId = $first['id'] ?? null;
        if (!$resolvable || !is_int($noteId)) {
            return null;
        }

        $author   = is_array($first['author'] ?? null) ? ($first['author']['username'] ?? null) : null;
        $position = is_array($first['position'] ?? null) ? $first['position'] : [];
        $last     = $humanNotes[count($humanNotes) - 1];

        $createdAt = $this->parseDate($first['created_at'] ?? null);
        $updatedAt = $this->parseDate($last['updated_at'] ?? null) ?? $createdAt;
        if ($createdAt === null || $updatedAt === null) {
            return null;
        }

        return new Thread(
            discussionId: $id,
            firstNoteId:  $noteId,
            author:       is_string($author) ? $author : '',
            body:         is_string($first['body'] ?? null) ? $first['body'] : '',
            resolved:     false,
            noteCount:    count($humanNotes),
            createdAt:    $createdAt,
            updatedAt:    $updatedAt,
            filePath:     is_string($position['new_path'] ?? null) ? $position['new_path'] : null,
            line:         is_int($position['new_line'] ?? null) ? $position['new_line'] : null,
        );
    }

    /**
     * Parse a GitLab timestamp (e.g. 2026-10-01T10:00:00.000Z), null when absent or malformed
     *
     * @param mixed $value The raw value
     *
     * @return ?DateTimeImmutable
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vp', $value);

        return $date === false ? null : $date;
    }
}
