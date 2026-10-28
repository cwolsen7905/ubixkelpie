<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use DateTimeImmutable;
use Kelpie\DataTransferObject\DiscussionNote;
use Kelpie\DataTransferObject\MergeRequestInfo;
use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Writes to a GitLab MR's threads: open a discussion, reply, edit a note, resolve a thread, label the MR; and reads one thread's notes
 *
 * Give it the bot client (`BotHttpClient`). It never touches code.
 * A failed call is logged and reported as null/false, never thrown.
 *
 * @see \Kelpie\Tests\GitLab\DiscussionWriterTest PHPUnit test case
 */
final class DiscussionWriter
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
     * Read the notes of one discussion, oldest first, system notes left out
     *
     * @param string $project      The project ID or URL-encodable path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     *
     * @return ?list<DiscussionNote> Null when the thread could not be read
     */
    public function notes(string $project, int $mrIid, string $discussionId): ?array
    {
        $discussion = $this->send('GET', $this->discussionUrl($project, $mrIid, $discussionId), null);
        if ($discussion === null) {
            return null;
        }

        $notes = [];
        foreach (is_array($discussion['notes'] ?? null) ? $discussion['notes'] : [] as $raw) {
            $note = is_array($raw) && ($raw['system'] ?? false) !== true ? $this->toNote($raw) : null;
            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * Read the head commit sha of an MR's source branch
     *
     * @param string $project The project ID or URL-encodable path
     * @param int    $mrIid   The MR IID
     *
     * @return ?string Null when the MR could not be read
     */
    public function headSha(string $project, int $mrIid): ?string
    {
        $sha = $this->send('GET', $this->mrUrl($project, $mrIid), null)['sha'] ?? null;

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /**
     * Read what decides whether the fixer may push to an MR's source branch
     *
     * @param string $project The project ID or URL-encodable path
     * @param int    $mrIid   The MR IID
     *
     * @return ?MergeRequestInfo Null when the MR could not be read or lacks a field
     */
    public function mergeRequest(string $project, int $mrIid): ?MergeRequestInfo
    {
        $mr = $this->send('GET', $this->mrUrl($project, $mrIid), null);
        if ($mr === null) {
            return null;
        }

        $sha    = $mr['sha'] ?? null;
        $source = $mr['source_branch'] ?? null;
        $target = $mr['target_branch'] ?? null;
        $from   = $mr['source_project_id'] ?? null;
        $to     = $mr['target_project_id'] ?? null;

        if (!is_string($sha) || $sha === '' || !is_string($source) || $source === '' || !is_string($target) || $target === '' || !is_int($from) || !is_int($to)) {
            return null;
        }

        $webUrl = $mr['web_url'] ?? '';

        return new MergeRequestInfo(
            $sha,
            $source,
            $target,
            ($mr['draft'] ?? false) === true,
            ($mr['state'] ?? '') === 'opened',
            $from,
            $to,
            is_string($webUrl) ? $webUrl : '',
            $this->strings($mr['labels'] ?? null),
        );
    }

    /**
     * Read the per-file diffs between two commits
     *
     * @param string $project The project ID or URL-encodable path
     * @param string $from    The older commit
     * @param string $to      The newer commit
     *
     * @return ?list<array{old_path: string, new_path: string, diff: string}> Null when the comparison could not be read
     */
    public function compare(string $project, string $from, string $to): ?array
    {
        $url  = sprintf('%s/projects/%s/repository/compare?from=%s&to=%s&straight=true', $this->apiUrl, rawurlencode($project), rawurlencode($from), rawurlencode($to));
        $data = $this->send('GET', $url, null);
        if ($data === null || !is_array($data['diffs'] ?? null)) {
            return null;
        }

        $files = [];
        foreach ($data['diffs'] as $raw) {
            if (is_array($raw) && is_string($raw['old_path'] ?? null) && is_string($raw['new_path'] ?? null) && is_string($raw['diff'] ?? null)) {
                $files[] = ['diff' => $raw['diff'], 'new_path' => $raw['new_path'], 'old_path' => $raw['old_path']];
            }
        }

        return $files;
    }

    /**
     * Reply in a thread
     *
     * @param string $project      The project ID or URL-encodable path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     * @param string $body         The reply
     *
     * @return ?int The new note ID, null on failure
     */
    public function reply(string $project, int $mrIid, string $discussionId, string $body): ?int
    {
        $created = $this->send('POST', $this->discussionUrl($project, $mrIid, $discussionId) . '/notes', ['body' => $body]);
        $id      = $created['id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Open a new MR-level discussion
     *
     * @param string $project The project ID or URL-encodable path
     * @param int    $mrIid   The MR IID
     * @param string $body    The first note
     *
     * @return ?string The new discussion ID, null on failure
     */
    public function open(string $project, int $mrIid, string $body): ?string
    {
        $created = $this->send('POST', $this->mrUrl($project, $mrIid) . '/discussions', ['body' => $body]);
        $id      = $created['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Open a new MR-level discussion and say which note it starts with
     *
     * @param string $project The project ID or URL-encodable path
     * @param int    $mrIid   The MR IID
     * @param string $body    The first note
     *
     * @return ?array{0: string, 1: int} The discussion ID and the first note's ID, null on failure
     */
    public function openNote(string $project, int $mrIid, string $body): ?array
    {
        $created = $this->send('POST', $this->mrUrl($project, $mrIid) . '/discussions', ['body' => $body]);
        $id      = $created['id'] ?? null;
        $notes   = is_array($created['notes'] ?? null) ? $created['notes'] : [];
        $first   = is_array($notes[0] ?? null) ? ($notes[0]['id'] ?? null) : null;

        return is_string($id) && $id !== '' && is_int($first) ? [$id, $first] : null;
    }

    /**
     * Rewrite the body of one of the bot's own notes
     *
     * @param string $project      The project ID or URL-encodable path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     * @param int    $noteId       The note ID
     * @param string $body         The new body
     *
     * @return bool Whether GitLab accepted it
     */
    public function editNote(string $project, int $mrIid, string $discussionId, int $noteId, string $body): bool
    {
        return $this->send('PUT', $this->discussionUrl($project, $mrIid, $discussionId) . '/notes/' . $noteId, ['body' => $body]) !== null;
    }

    /**
     * Resolve a thread
     *
     * @param string $project      The project ID or URL-encodable path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     *
     * @return bool Whether GitLab accepted it
     */
    public function resolve(string $project, int $mrIid, string $discussionId): bool
    {
        return $this->send('PUT', $this->discussionUrl($project, $mrIid, $discussionId), ['resolved' => 'true']) !== null;
    }

    /**
     * Add a label to the MR (other labels are kept)
     *
     * @param string $project The project ID or URL-encodable path
     * @param int    $mrIid   The MR IID
     * @param string $label   The label
     *
     * @return bool Whether GitLab accepted it
     */
    public function addLabel(string $project, int $mrIid, string $label): bool
    {
        return $this->send('PUT', $this->mrUrl($project, $mrIid), ['add_labels' => $label]) !== null;
    }

    /**
     * Remove a label from the MR (other labels are kept)
     *
     * @param string $project The project ID or URL-encodable path
     * @param int    $mrIid   The MR IID
     * @param string $label   The label
     *
     * @return bool Whether GitLab accepted it
     */
    public function removeLabel(string $project, int $mrIid, string $label): bool
    {
        return $this->send('PUT', $this->mrUrl($project, $mrIid), ['remove_labels' => $label]) !== null;
    }

    /**
     * Replace labels on the MR in one request: others are kept, the ones to add are added, the ones to remove are removed
     *
     * @param string   $project The project ID or URL-encodable path
     * @param int      $mrIid   The MR IID
     * @param string[] $add     Labels to add
     * @param string[] $remove  Labels to remove
     *
     * @return bool Whether GitLab accepted it
     */
    public function changeLabels(string $project, int $mrIid, array $add, array $remove): bool
    {
        $body = [];
        if ($add !== []) {
            $body['add_labels'] = implode(',', $add);
        }
        if ($remove !== []) {
            $body['remove_labels'] = implode(',', $remove);
        }

        return $body === [] || $this->send('PUT', $this->mrUrl($project, $mrIid), $body) !== null;
    }

    /**
     * Create the labels the project does not have yet
     *
     * @param string               $project The project ID or URL-encodable path
     * @param array<string,string> $labels  Label title => colour
     *
     * @return bool Whether every label exists now
     */
    public function ensureLabels(string $project, array $labels): bool
    {
        $url      = sprintf('%s/projects/%s/labels', $this->apiUrl, rawurlencode($project));
        $existing = $this->send('GET', $url . '?per_page=100', null);
        if ($existing === null) {
            return false;
        }

        $have = [];
        foreach ($existing as $label) {
            $title = is_array($label) ? ($label['name'] ?? null) : null;
            if (is_string($title)) {
                $have[] = $title;
            }
        }

        $ok = true;
        foreach ($labels as $title => $colour) {
            if (!in_array($title, $have, true) && $this->send('POST', $url, ['color' => $colour, 'name' => $title]) === null) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Put an emoji on a note of the MR
     *
     * @param string $project The project ID or URL-encodable path
     * @param int    $mrIid   The MR IID
     * @param int    $noteId  The note ID
     * @param string $emoji   The GitLab emoji name, e.g. `eyes`
     *
     * @return bool Whether GitLab accepted it (it refuses a second one of the same name by the same user)
     */
    public function awardEmoji(string $project, int $mrIid, int $noteId, string $emoji): bool
    {
        return $this->send('POST', $this->mrUrl($project, $mrIid) . '/notes/' . $noteId . '/award_emoji', ['name' => $emoji]) !== null;
    }

    /**
     * @param mixed $value A decoded list of strings
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param string $project The project ID or path
     * @param int    $mrIid   The MR IID
     *
     * @return string
     */
    private function mrUrl(string $project, int $mrIid): string
    {
        return sprintf('%s/projects/%s/merge_requests/%d', $this->apiUrl, rawurlencode($project), $mrIid);
    }

    /**
     * @param string $project      The project ID or path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     *
     * @return string
     */
    private function discussionUrl(string $project, int $mrIid, string $discussionId): string
    {
        return $this->mrUrl($project, $mrIid) . '/discussions/' . rawurlencode($discussionId);
    }

    /**
     * Send one request
     *
     * @param string                 $method The HTTP method
     * @param string                 $url    The URL
     * @param ?array<string, string> $body   The JSON body, when there is one
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
                $this->logger->error('GitLab write: body is not encodable', ['error' => $e->getMessage()]);

                return null;
            }
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streamFactory->createStream($json));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientException $e) {
            $this->logger->error('GitLab write: request failed', ['error' => $e->getMessage(), 'method' => $method, 'url' => $url]);

            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $this->logger->error('GitLab write: unexpected HTTP status', ['method' => $method, 'status' => $response->getStatusCode(), 'url' => $url]);

            return null;
        }

        try {
            return $this->jsonService->decode((string) $response->getBody());
        } catch (DtoException $e) {
            $this->logger->error('GitLab write: JSON decode failed', ['error' => $e->getMessage(), 'url' => $url]);

            return null;
        }
    }

    /**
     * @param array<mixed> $raw One decoded note
     *
     * @return ?DiscussionNote
     */
    private function toNote(array $raw): ?DiscussionNote
    {
        $id     = $raw['id'] ?? null;
        $author = is_array($raw['author'] ?? null) ? ($raw['author']['username'] ?? null) : null;
        $when   = is_string($raw['created_at'] ?? null) ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vp', $raw['created_at']) : false;

        if (!is_int($id) || $when === false) {
            return null;
        }

        return new DiscussionNote($id, is_string($raw['body'] ?? null) ? $raw['body'] : '', is_string($author) ? $author : '', $when);
    }
}
