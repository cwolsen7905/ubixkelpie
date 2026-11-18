<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;

/**
 * An in-memory GitLab for the issue fix: issue 7 of org/proj, the merge requests of a branch, one reviewer, and a request log
 */
final class FakeFixGitlab implements HttpClient
{
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
     * The merge request web URLs open for any branch
     *
     * @var list<string>
     */
    private array $openMergeRequests = [];

    /**
     * Whether the merge request POST answers 500
     */
    private bool $mergeRequestFails = false;

    /**
     * Whether the notes POST answers 500
     */
    private bool $commentFails = false;

    /**
     * Whether the issue GET answers 500
     */
    private bool $issueFails = false;

    /**
     * The notes posted on the issue
     *
     * @var list<string>
     */
    private array $notes = [];

    /**
     * The labels added to the issue
     *
     * @var list<string>
     */
    private array $labelsAdded = [];

    /**
     * The sha `dev` points at, empty for a project with no such branch
     */
    private string $branchSha = '';

    /**
     * Constructor
     *
     * @param string[] $labels       The labels on the issue
     * @param bool     $confidential Whether the issue is confidential
     */
    public function __construct(
        private readonly array $labels = ['kelpie::fix'],
        private readonly bool $confidential = false,
    ) {
    }

    /**
     * Say where `dev` points
     *
     * @param string $sha The commit sha
     *
     * @return self
     */
    public function withBranchSha(string $sha): self
    {
        $this->branchSha = $sha;

        return $this;
    }

    /**
     * Make the issue GET answer 500
     *
     * @return self
     */
    public function failIssue(): self
    {
        $this->issueFails = true;

        return $this;
    }

    /**
     * Make the merge request POST answer 500
     *
     * @return self
     */
    public function failMergeRequest(): self
    {
        $this->mergeRequestFails = true;

        return $this;
    }

    /**
     * Make the notes POST answer 500
     *
     * @return self
     */
    public function failComment(): self
    {
        $this->commentFails = true;

        return $this;
    }

    /**
     * Open a merge request for any branch
     *
     * @param string $url Its web URL
     *
     * @return self
     */
    public function withOpenMergeRequest(string $url): self
    {
        $this->openMergeRequests[] = $url;

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
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return list<string>
     */
    public function labelsAdded(): array
    {
        return $this->labelsAdded;
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

        if ($method === 'GET' && $path === '/api/v4/projects/org%2Fproj/issues/7') {
            return $this->issueFails ? new Psr7Response(500, [], '{}') : new Psr7Response(200, [], $json->encode(['author' => ['username' => 'alice'], 'confidential' => $this->confidential, 'description' => 'There is a typo, teh, in src/a.php', 'labels' => $this->labels, 'title' => 'Typo in a doc comment!']));
        }
        if ($method === 'GET' && $path === '/api/v4/projects/org%2Fproj/merge_requests') {
            return new Psr7Response(200, [], $json->encode($this->openMergeRequestsJson()));
        }
        if ($method === 'GET' && $path === '/api/v4/projects/org%2Fproj/repository/branches/dev') {
            return $this->branchSha === '' ? new Psr7Response(404, [], '{}') : new Psr7Response(200, [], $json->encode(['commit' => ['id' => $this->branchSha]]));
        }
        if ($method === 'GET' && $path === '/api/v4/users') {
            return new Psr7Response(200, [], '[{"id":9,"username":"reviewer"}]');
        }
        if ($method === 'POST' && $path === '/api/v4/projects/org%2Fproj/merge_requests') {
            return $this->mergeRequestFails ? new Psr7Response(500, [], '{}') : new Psr7Response(201, [], '{"web_url":"https://gitlab.test/org/proj/-/merge_requests/12"}');
        }
        if ($method === 'POST' && $path === '/api/v4/projects/org%2Fproj/issues/7/notes') {
            if ($this->commentFails) {
                return new Psr7Response(500, [], '{}');
            }
            $this->notes[] = is_string($body['body'] ?? null) ? $body['body'] : '';

            return new Psr7Response(201, [], '{"id":1}');
        }
        if ($method === 'PUT' && $path === '/api/v4/projects/org%2Fproj/issues/7') {
            $this->labelsAdded[] = is_string($body['add_labels'] ?? null) ? $body['add_labels'] : '';

            return new Psr7Response(200, [], '{}');
        }

        return new Psr7Response(404, [], '{}');
    }

    /**
     * @return list<array{web_url: string}>
     */
    private function openMergeRequestsJson(): array
    {
        $rows = [];
        foreach ($this->openMergeRequests as $url) {
            $rows[] = ['web_url' => $url];
        }

        return $rows;
    }
}
