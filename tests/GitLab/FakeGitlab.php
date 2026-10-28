<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use DateTimeImmutable;
use DateTimeZone;
use Kelpie\GitLab\DiscussionWriter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;

/**
 * An in-memory GitLab for the MR 3 / project 1 threads: notes per discussion, MR labels, a request log
 *
 * Recorded requests are "METHOD path" strings.
 */
final class FakeGitlab implements HttpClient
{
    /**
     * Notes by discussion ID: id, body, author, system, created_at
     *
     * @var array<string, list<array{id: int, body: string, author: string, system: bool, created_at: string}>>
     */
    private array $notes = [];

    /**
     * Labels on the MR
     *
     * @var list<string>
     */
    private array $labels = [];

    /**
     * Labels the project has
     *
     * @var list<string>
     */
    private array $projectLabels = [];

    /**
     * Note IDs that got an emoji, as "id:name"
     *
     * @var list<string>
     */
    private array $awards = [];

    /**
     * Whether resolving a thread answers with an error
     */
    private bool $resolveFails = false;

    /**
     * The per-file diffs a commit comparison answers with, null for a comparison that fails
     *
     * @var ?list<array{old_path: string, new_path: string, diff: string}>
     */
    private ?array $compareDiffs = [];

    /**
     * Discussion IDs resolved through the API
     *
     * @var list<string>
     */
    private array $resolved = [];

    /**
     * "METHOD path" of every request
     *
     * @var list<string>
     */
    private array $requests = [];

    /**
     * Called after every POSTed note with the discussion ID, so a test can play a racing worker
     *
     * @var ?callable(string): void
     */
    private $afterPost = null;

    /**
     * Fields of the MR that override the defaults (sha, source_branch, source_project_id, ...)
     *
     * @var array<string, mixed>
     */
    private array $mrFields = [];

    /**
     * The head pipeline of the MR: id, status, and its failed jobs; null for none
     *
     * @var ?array{id: int, status: string, jobs: list<array<string, mixed>>}
     */
    private ?array $pipeline = null;

    /**
     * The log of every job
     */
    private string $trace = '';

    /**
     * Message and date of the head commit
     *
     * @var array{message: string, committed_date: string}
     */
    private array $commit = ['message' => 'feat: x', 'committed_date' => '2026-01-01T00:00:00.000Z'];

    /**
     * Next note ID to hand out
     */
    private int $nextId = 100;

    /**
     * Set what a commit comparison answers with
     *
     * @param ?list<array{old_path: string, new_path: string, diff: string}> $files The changed files, null to make the comparison fail
     *
     * @return void
     */
    public function compareDiffs(?array $files): void
    {
        $this->compareDiffs = $files;
    }

    /**
     * Override fields of the MR as GitLab returns it
     *
     * @param array<string, mixed> $fields The fields
     *
     * @return void
     */
    public function mergeRequestFields(array $fields): void
    {
        $this->mrFields = $fields;
    }

    /**
     * Give the MR a head pipeline
     *
     * @param string                     $status The pipeline status
     * @param list<array<string, mixed>> $jobs   Its failed jobs, as GitLab lists them
     * @param string                     $trace  The log of any job
     *
     * @return void
     */
    public function pipeline(string $status, array $jobs, string $trace = ''): void
    {
        $this->pipeline = ['id' => 9, 'jobs' => $jobs, 'status' => $status];
        $this->trace    = $trace;
    }

    /**
     * Set the head commit
     *
     * @param string $message The message
     * @param string $date    The committed date
     *
     * @return void
     */
    public function commit(string $message, string $date): void
    {
        $this->commit = ['committed_date' => $date, 'message' => $message];
    }

    /**
     * The notes by discussion ID
     *
     * @return array<string, list<array{id: int, body: string, author: string, system: bool, created_at: string}>>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Make resolving a thread fail from now on
     *
     * @return void
     */
    public function failResolve(): void
    {
        $this->resolveFails = true;
    }

    /**
     * The discussion IDs that were resolved
     *
     * @return list<string>
     */
    public function resolved(): array
    {
        return $this->resolved;
    }

    /**
     * The labels on the MR
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * Put labels on the MR, as if a person had
     *
     * @param string[] $labels The labels
     *
     * @return void
     */
    public function withLabels(array $labels): void
    {
        $this->labels = array_values($labels);
    }

    /**
     * The labels the project has
     *
     * @return list<string>
     */
    public function projectLabels(): array
    {
        return $this->projectLabels;
    }

    /**
     * The emoji given, as "noteId:name"
     *
     * @return list<string>
     */
    public function awards(): array
    {
        return $this->awards;
    }

    /**
     * The "METHOD path" of every request so far
     *
     * @return list<string>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * Run a callback after the next POSTed note, to play a racing worker
     *
     * @param callable(string): void $callback Gets the discussion ID
     *
     * @return void
     */
    public function onPost(callable $callback): void
    {
        $this->afterPost = $callback;
    }

    /**
     * Seed a note
     *
     * @param string $discussionId The discussion
     * @param string $body         The body
     * @param string $age          How old (a DateTime modifier, e.g. "-5 minutes")
     * @param ?int   $id           The note ID (the next free one when null)
     * @param string $author       The author
     *
     * @return int The note ID
     */
    public function note(string $discussionId, string $body, string $age = 'now', ?int $id = null, string $author = 'alice'): int
    {
        $id         ??= $this->nextId;
        $this->nextId = max($this->nextId, $id) + 1;

        $this->notes[$discussionId][] = [
            'author'     => $author,
            'body'       => $body,
            'created_at' => (new DateTimeImmutable($age, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'id'         => $id,
            'system'     => false,
        ];

        return $id;
    }

    /**
     * Build a DiscussionWriter and the HTTP client under it, both over the fake
     *
     * @param Logger $logger Logger
     *
     * @return DiscussionWriter
     */
    public function writer(Logger $logger): DiscussionWriter
    {
        return new DiscussionWriter($logger, $this, new Psr17Factory(), new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService($logger));
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Response
    {
        $method = $request->getMethod();
        $path   = str_replace('/api/v4', '', $request->getUri()->getPath());
        $raw    = (string) $request->getBody();
        $body   = $raw === '' ? [] : (new JsonService(new NullLogger()))->decode($raw);

        $this->requests[] = $method . ' ' . $path;

        if (preg_match('#^/projects/[^/]+/merge_requests$#', $path) === 1 && $method === 'GET') {
            return new Psr7Response(200, [], (new JsonService(new NullLogger()))->encode([
                [
                    'draft'         => false,
                    'head_pipeline' => $this->pipeline === null ? null : ['id' => $this->pipeline['id'], 'status' => $this->pipeline['status'], 'user' => ['username' => 'alice']],
                    'iid'           => 3,
                    'project_id'    => 1,
                    'sha'           => str_repeat('a', 40),
                    'source_branch' => 'feat/x',
                    'target_branch' => 'dev',
                    'title'         => 'A change',
                    ...$this->mrFields,
                ],
            ]));
        }
        if (preg_match('#^/projects/[^/]+/repository/compare$#', $path) === 1 && $method === 'GET') {
            return $this->compareDiffs === null ? $this->fakeJson(['message' => '404 Not found'], 404) : $this->fakeJson(['diffs' => $this->compareDiffs]);
        }
        if (preg_match('#^/projects/\d+/repository/commits/\w+$#', $path) === 1 && $method === 'GET') {
            return $this->fakeJson($this->commit);
        }
        if (preg_match('#^/projects/\d+/pipelines/9/jobs$#', $path) === 1 && $method === 'GET') {
            return new Psr7Response(200, [], (new JsonService(new NullLogger()))->encode($this->pipeline['jobs'] ?? []));
        }
        if (preg_match('#^/projects/\d+/jobs/\d+/trace$#', $path) === 1 && $method === 'GET') {
            return new Psr7Response(200, [], $this->trace);
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3/discussions$#', $path) === 1 && $method === 'POST') {
            $id     = 'ab' . str_pad((string) $this->nextId, 6, '0', STR_PAD_LEFT);
            $noteId = $this->note($id, $this->fakeString($body['body'] ?? null), 'now', null, 'kelpie-bot');

            return $this->fakeJson(['id' => $id, 'notes' => [['id' => $noteId]]], 201);
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3/discussions/(\w+)/notes/(\d+)$#', $path, $m) === 1 && $method === 'PUT') {
            if (!isset($this->notes[$m[1]])) {
                return $this->fakeJson(['message' => '404 Not found'], 404);
            }
            foreach ($this->notes[$m[1]] as $i => $note) {
                if ($note['id'] === (int) $m[2]) {
                    $this->notes[$m[1]][$i]['body'] = $this->fakeString($body['body'] ?? null);
                }
            }

            return $this->fakeJson(['id' => (int) $m[2]]);
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3/discussions/(\w+)/notes$#', $path, $m) === 1 && $method === 'POST') {
            if (!isset($this->notes[$m[1]])) {
                return $this->fakeJson(['message' => '404 Not found'], 404);
            }
            $id = $this->note($m[1], $this->fakeString($body['body'] ?? null), 'now', null, 'kelpie-bot');
            if ($this->afterPost !== null) {
                $callback        = $this->afterPost;
                $this->afterPost = null;
                $callback($m[1]);
            }

            return $this->fakeJson(['id' => $id], 201);
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3/discussions$#', $path) === 1 && $method === 'GET') {
            $all = [];
            foreach (array_keys($this->notes) as $id) {
                $all[] = $this->fakeDiscussion((string) $id);
            }

            return new Psr7Response(200, [], (new JsonService(new NullLogger()))->encode($all));
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3/discussions/(\w+)$#', $path, $m) === 1 && $method === 'PUT') {
            if (!isset($this->notes[$m[1]])) {
                return $this->fakeJson(['message' => '404 Not found'], 404);
            }
            if ($this->resolveFails) {
                return $this->fakeJson(['message' => '403 Forbidden'], 403);
            }
            if (($body['resolved'] ?? '') === 'true') {
                $this->resolved[] = $m[1];
            }

            return $this->fakeJson($this->fakeDiscussion($m[1]));
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3/discussions/(\w+)$#', $path, $m) === 1 && $method === 'GET') {
            return isset($this->notes[$m[1]]) ? $this->fakeJson($this->fakeDiscussion($m[1])) : $this->fakeJson(['message' => '404 Not found'], 404);
        }
        if (preg_match('#^/projects/[^/]+/labels$#', $path) === 1) {
            if ($method === 'POST') {
                $this->projectLabels[] = $this->fakeString($body['name'] ?? null);

                return $this->fakeJson(['name' => $this->fakeString($body['name'] ?? null)], 201);
            }

            $listed = [];
            foreach ($this->projectLabels as $name) {
                $listed[] = ['name' => $name];
            }

            return new Psr7Response(200, [], (new JsonService(new NullLogger()))->encode($listed));
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3/notes/(\d+)/award_emoji$#', $path, $m) === 1 && $method === 'POST') {
            $award = $m[1] . ':' . $this->fakeString($body['name'] ?? null);
            if (in_array($award, $this->awards, true)) {
                return $this->fakeJson(['message' => '404 Award Emoji Name has already been taken'], 404);
            }
            $this->awards[] = $award;

            return $this->fakeJson(['name' => $this->fakeString($body['name'] ?? null)], 201);
        }
        if (preg_match('#^/projects/[^/]+/merge_requests/3$#', $path) === 1) {
            if ($method === 'PUT') {
                $add          = isset($body['add_labels']) ? explode(',', $this->fakeString($body['add_labels'])) : [];
                $remove       = isset($body['remove_labels']) ? explode(',', $this->fakeString($body['remove_labels'])) : [];
                $this->labels = array_values(array_diff(array_unique([...$this->labels, ...$add]), $remove));
            }

            return $this->fakeJson([
                'draft'             => false,
                'iid'               => 3,
                'labels'            => $this->labels,
                'sha'               => str_repeat('a', 40),
                'source_branch'     => 'feat/x',
                'source_project_id' => 1,
                'state'             => 'opened',
                'target_branch'     => 'dev',
                'target_project_id' => 1,
                'web_url'           => 'https://gitlab.test/g/p/-/merge_requests/3',
                ...$this->mrFields,
            ]);
        }

        return $this->fakeJson(['message' => '404 Not found'], 404);
    }

    /**
     * @param string $id The discussion ID
     *
     * @return array<string, mixed>
     */
    private function fakeDiscussion(string $id): array
    {
        return [
            'id'    => $id,
            'notes' => array_map(static function (array $note): array {
                return [
                    ...$note,
                    'author'     => ['username' => $note['author']],
                    'resolvable' => true,
                    'resolved'   => false,
                    'updated_at' => $note['created_at'],
                ];
            }, $this->notes[$id]),
        ];
    }

    /**
     * @param array<string, mixed> $data   The body
     * @param int                  $status The status
     *
     * @return Psr7Response
     */
    private function fakeJson(array $data, int $status = 200): Psr7Response
    {
        return new Psr7Response($status, [], (new JsonService(new NullLogger()))->encode($data));
    }

    /**
     * @param mixed $value A decoded request value
     *
     * @return string
     */
    private function fakeString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
