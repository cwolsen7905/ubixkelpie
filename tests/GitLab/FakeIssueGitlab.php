<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;

/**
 * An in-memory GitLab for one issue (project org/proj, issue 7): its fields, its notes and a request log
 *
 * A POST to the notes adds a note by the bot, a PUT adds the labels, so a second run sees what the first did.
 */
final class FakeIssueGitlab implements HttpClient
{
    public const string ISSUE = '/api/v4/projects/org%2Fproj/issues/7';

    /**
     * "METHOD path" of every request
     *
     * @var list<string>
     */
    private array $requests = [];

    /**
     * The decoded JSON body of every request that had one
     *
     * @var list<array<string, mixed>>
     */
    private array $bodies = [];

    /**
     * The notes on the issue: author and body
     *
     * @var list<array{author: string, body: string}>
     */
    private array $notes = [];

    /**
     * The labels on the issue
     *
     * @var list<string>
     */
    private array $labels = [];

    /**
     * Methods that answer 500, such as "POST"
     *
     * @var list<string>
     */
    private array $failing = [];

    /**
     * Constructor
     *
     * @param string $title        The issue title
     * @param string $description  The issue description
     * @param string $author       The issue author
     * @param bool   $confidential Whether the issue is confidential
     */
    public function __construct(
        private readonly string $title = 'Crash on save',
        private readonly string $description = 'Saving twice throws.',
        private readonly string $author = 'alice',
        private readonly bool $confidential = false,
    ) {
    }

    /**
     * Put a label on the issue
     *
     * @param string $label The label
     *
     * @return self
     */
    public function withLabel(string $label): self
    {
        $this->labels[] = $label;

        return $this;
    }

    /**
     * Add a note to the issue
     *
     * @param string $author The note's author
     * @param string $body   The note's body
     *
     * @return self
     */
    public function withNote(string $author, string $body): self
    {
        $this->notes[] = ['author' => $author, 'body' => $body];

        return $this;
    }

    /**
     * Make every request with this method answer 500
     *
     * @param string $method The method
     *
     * @return self
     */
    public function failing(string $method): self
    {
        $this->failing[] = $method;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bodies(): array
    {
        return $this->bodies;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * The bodies of the notes by one user
     *
     * @param string $author The user
     *
     * @return list<string>
     */
    public function notesBy(string $author): array
    {
        $bodies = [];
        foreach ($this->notes as $note) {
            if ($note['author'] === $author) {
                $bodies[] = $note['body'];
            }
        }

        return $bodies;
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Response
    {
        $method           = $request->getMethod();
        $path             = $request->getUri()->getPath();
        $this->requests[] = $method . ' ' . $path;

        $json = new JsonService(new NullLogger());
        $raw  = (string) $request->getBody();
        /**
 * @var array<string, mixed> $body
*/
        $body = $raw === '' ? [] : $json->decode($raw);
        if ($raw !== '') {
            $this->bodies[] = $body;
        }

        if (in_array($method, $this->failing, true)) {
            return new Psr7Response(500, [], '{}');
        }

        if ($method === 'POST' && $path === self::ISSUE . '/notes') {
            $this->notes[] = ['author' => 'kelpie-bot', 'body' => is_string($body['body'] ?? null) ? $body['body'] : ''];

            return new Psr7Response(201, [], '{"id":1}');
        }

        if ($method === 'PUT' && $path === self::ISSUE) {
            $this->labels[] = is_string($body['add_labels'] ?? null) ? $body['add_labels'] : '';

            return new Psr7Response(200, [], '{}');
        }

        if ($method === 'GET' && $path === self::ISSUE . '/notes') {
            $out = [];
            foreach ($this->notes as $note) {
                $out[] = ['author' => ['username' => $note['author']], 'body' => $note['body']];
            }

            return new Psr7Response(200, [], $json->encode($out));
        }

        if ($method === 'GET' && $path === self::ISSUE) {
            return new Psr7Response(200, [], $json->encode([
                'author'       => ['username' => $this->author],
                'confidential' => $this->confidential,
                'description'  => $this->description,
                'labels'       => $this->labels,
                'title'        => $this->title,
            ]));
        }

        return new Psr7Response(404, [], '{}');
    }
}
