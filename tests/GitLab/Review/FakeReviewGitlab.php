<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab\Review;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;

/**
 * An in-memory GitLab for merge request 3 of project 1: discussions, notes, the user, the diff version, and a request log
 */
final class FakeReviewGitlab implements HttpClient
{
    /**
     * The discussions, as GitLab returns them
     *
     * @var list<array<mixed>>
     */
    private array $discussions = [];

    /**
     * Every request: method, path and decoded body
     *
     * @var list<array{method: string, path: string, body: array<mixed>}>
     */
    private array $requests = [];

    /**
     * Whether an inline (positioned) thread is refused with 400
     */
    private bool $refuseInline = false;

    /**
     * Whether every POST and PUT is refused with 500
     */
    private bool $refuseWrites = false;

    /**
     * Whether reading the discussions fails
     */
    private bool $failReads = false;

    /**
     * Called after each discussions read, so a test can play a racing job
     *
     * @var ?callable(self): void
     */
    private $afterRead = null;

    private int $nextId = 100;

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();
        $json = $body === '' ? [] : $this->jsonService()->decode($body);

        $this->requests[] = ['body' => $json, 'method' => $request->getMethod(), 'path' => $path];

        if ($request->getHeaderLine('PRIVATE-TOKEN') === '') {
            return new Psr7Response(401);
        }
        if ($request->getMethod() === 'GET') {
            return $this->read($path, $request->getUri()->getQuery());
        }
        if ($this->refuseWrites || ($this->refuseInline && isset($json['position']))) {
            return new Psr7Response($this->refuseWrites ? 500 : 400, [], '{"message":"refused"}');
        }

        return $this->write($request->getMethod(), $path, $json);
    }

    /**
     * Set the discussions the API returns
     *
     * @param list<array<mixed>> $discussions The discussions
     *
     * @return self
     */
    public function withDiscussions(array $discussions): self
    {
        $this->discussions = $discussions;

        return $this;
    }

    /**
     * The discussions now
     *
     * @return list<array<mixed>>
     */
    public function discussions(): array
    {
        return $this->discussions;
    }

    /**
     * Refuse inline threads (400) from now on
     *
     * @return self
     */
    public function refuseInline(): self
    {
        $this->refuseInline = true;

        return $this;
    }

    /**
     * Refuse every write (500) from now on
     *
     * @return self
     */
    public function refuseWrites(): self
    {
        $this->refuseWrites = true;

        return $this;
    }

    /**
     * Fail reading the discussions from now on
     *
     * @return self
     */
    public function failReads(): self
    {
        $this->failReads = true;

        return $this;
    }

    /**
     * Run a callback once, after the next discussions read, to play a job that posts meanwhile
     *
     * @param callable(self): void $callback The callback
     *
     * @return self
     */
    public function afterNextRead(callable $callback): self
    {
        $this->afterRead = $callback;

        return $this;
    }

    /**
     * The body of the first note of the discussion at a position
     *
     * @param int $index The position in the discussions, from 0
     *
     * @return string
     */
    public function firstNoteBody(int $index): string
    {
        $notes = $this->discussions[$index]['notes'] ?? null;
        $note  = is_array($notes) ? ($notes[0] ?? null) : null;
        $body  = is_array($note) ? ($note['body'] ?? null) : null;

        return is_string($body) ? $body : '';
    }

    /**
     * Add a discussion as if another job had posted it
     *
     * @param array<string, mixed> $discussion The discussion
     *
     * @return self
     */
    public function addDiscussion(array $discussion): self
    {
        $this->discussions[] = $discussion;

        return $this;
    }

    /**
     * Every request: method, path and decoded body
     *
     * @return list<array{method: string, path: string, body: array<mixed>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * The paths of the requests with a method
     *
     * @param string $method The method
     *
     * @return list<string>
     */
    public function paths(string $method): array
    {
        $paths = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === $method) {
                $paths[] = $request['path'];
            }
        }

        return $paths;
    }

    /**
     * The bodies of the POSTs to the discussions endpoint
     *
     * @return list<array<mixed>>
     */
    public function threads(): array
    {
        $out = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST' && str_ends_with($request['path'], '/discussions')) {
                $out[] = $request['body'];
            }
        }

        return $out;
    }

    /**
     * The bodies of every note the review wrote or replaced (POST /notes and PUT /notes/:id)
     *
     * @return list<string>
     */
    public function noteBodies(): array
    {
        $out = [];
        foreach ($this->requests as $request) {
            $isNote = ($request['method'] === 'POST' && str_ends_with($request['path'], '/notes')) || ($request['method'] === 'PUT' && str_contains($request['path'], '/notes/'));
            $body   = $request['body']['body'] ?? '';
            if ($isNote && is_string($body)) {
                $out[] = $body;
            }
        }

        return $out;
    }

    /**
     * @param string $path  The request path
     * @param string $query The query string
     *
     * @return Response
     */
    private function read(string $path, string $query): Response
    {
        if (str_ends_with($path, '/user')) {
            return $this->json(['id' => 9]);
        }
        if (str_ends_with($path, '/versions')) {
            return $this->json([['base_commit_sha' => 'b1', 'head_commit_sha' => 'h1', 'start_commit_sha' => 's1']]);
        }
        if ($this->failReads) {
            return new Psr7Response(500);
        }

        parse_str($query, $params);
        $perPage = is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : 100;
        $page    = is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1;

        $discussions = array_slice($this->discussions, ($page - 1) * $perPage, $perPage);
        if ($this->afterRead !== null) {
            ($this->afterRead)($this);
            $this->afterRead = null;
        }

        return $this->json($discussions);
    }

    /**
     * @param string       $method The method
     * @param string       $path   The request path
     * @param array<mixed> $json   The body
     *
     * @return Response
     */
    private function write(string $method, string $path, array $json): Response
    {
        $id = $this->nextId;
        $this->nextId++;
        $body = is_string($json['body'] ?? null) ? $json['body'] : '';
        if ($method === 'PUT') {
            $id = (int) basename($path);
            foreach ($this->discussions as $d => $discussion) {
                $notes = is_array($discussion['notes']) ? $discussion['notes'] : [];
                foreach ($notes as $n => $note) {
                    if (is_array($note) && $note['id'] === $id) {
                        $notes[$n] = ['body' => $body] + $note;
                    }
                }
                $this->discussions[$d]['notes'] = $notes;
            }

            return $this->json(['id' => $id]);
        }

        $this->discussions[] = ['id' => 'd' . $id, 'notes' => [['author' => ['id' => 9], 'body' => $body, 'id' => $id, 'resolved' => str_ends_with($path, '/notes') ? null : false]]];

        return $this->json(['id' => 'd' . $id]);
    }

    /**
     * @param array<mixed> $data The data
     *
     * @return Response
     */
    private function json(array $data): Response
    {
        return new Psr7Response(200, [], $this->jsonService()->encode($data));
    }

    /**
     * @return JsonService
     */
    private function jsonService(): JsonService
    {
        return new JsonService(new NullLogger());
    }
}
