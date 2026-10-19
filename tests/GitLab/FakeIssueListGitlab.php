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
 * An in-memory GitLab issue list per project, paged by 100 like the real one, with a request log
 */
final class FakeIssueListGitlab implements HttpClient
{
    /**
     * "METHOD path?query" of every request
     *
     * @var string[]
     */
    private array $requests = [];

    /**
     * The issues per project path: iid, author, confidential, labels
     *
     * @var array<string, array<int, array{iid: int, author: string, confidential: bool, labels: string[]}>>
     */
    private array $projects = [];

    /**
     * Project paths whose list answers 500
     *
     * @var string[]
     */
    private array $failing = [];

    /**
     * Add an open issue to a project
     *
     * @param string   $project      The project path
     * @param int      $iid          The issue number
     * @param string   $author       The author's username
     * @param bool     $confidential Whether it is confidential
     * @param string[] $labels       Its labels
     *
     * @return self
     */
    public function withIssue(string $project, int $iid, string $author = 'alice', bool $confidential = false, array $labels = []): self
    {
        $this->projects[$project][] = ['author' => $author, 'confidential' => $confidential, 'iid' => $iid, 'labels' => $labels];

        return $this;
    }

    /**
     * Make a project's list answer 500
     *
     * @param string $project The project path
     *
     * @return self
     */
    public function failing(string $project): self
    {
        $this->failing[] = $project;

        return $this;
    }

    /**
     * @return string[]
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Response
    {
        $this->requests[] = $request->getMethod() . ' ' . $request->getUri()->getPath() . '?' . $request->getUri()->getQuery();

        if ($request->getMethod() !== 'GET' || preg_match('#/projects/([^/]+)/issues$#', $request->getUri()->getPath(), $m) !== 1) {
            return new Psr7Response(404, [], '{}');
        }

        $project = rawurldecode($m[1]);
        if (in_array($project, $this->failing, true)) {
            return new Psr7Response(500, [], '{}');
        }

        parse_str($request->getUri()->getQuery(), $query);
        $page = is_string($query['page'] ?? null) ? max(1, (int) $query['page']) : 1;
        $out  = [];
        foreach (array_slice($this->projects[$project] ?? [], ($page - 1) * 100, 100) as $issue) {
            $out[] = ['author' => ['username' => $issue['author']], 'confidential' => $issue['confidential'], 'iid' => $issue['iid'], 'labels' => $issue['labels']];
        }

        return new Psr7Response(200, [], (new JsonService(new NullLogger()))->encode($out));
    }
}
