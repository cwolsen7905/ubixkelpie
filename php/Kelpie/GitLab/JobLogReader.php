<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Reads a failed pipeline's jobs and the tail of a job's log; GET only
 *
 * The log is untrusted and may hold secrets: only the tail is kept, terminal control codes are
 * dropped, anything that looks like a credential is masked, and Kelpie markers and @-mentions
 * are defused before the text can reach a prompt or an MR note.
 *
 * @see \Kelpie\Tests\GitLab\JobLogReaderTest PHPUnit test case
 */
final class JobLogReader
{
    public const int TAIL_BYTES = 8192;

    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param string         $apiUrl         GitLab API base URL
     * @param JsonService    $jsonService    JSON service
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
     * The failed jobs of a pipeline, as the Pipeline Hook `builds` list shapes them
     *
     * @param int $projectId  The GitLab project ID
     * @param int $pipelineId The pipeline ID
     *
     * @return list<array{id: int, name: string, stage: string, status: string, allow_failure: bool}>
     */
    public function failedJobs(int $projectId, int $pipelineId): array
    {
        $body = $this->get(sprintf('%s/projects/%d/pipelines/%d/jobs?scope[]=failed&per_page=100', $this->apiUrl, $projectId, $pipelineId));
        if ($body === null) {
            return [];
        }

        try {
            $items = $this->jsonService->decode($body);
        } catch (DtoException $e) {
            $this->logger->error('GitLab jobs: JSON decode failed', ['error' => $e->getMessage(), 'pipeline_id' => $pipelineId]);

            return [];
        }

        $jobs = [];
        foreach ($items as $item) {
            if (is_array($item) && is_int($item['id'] ?? null)) {
                $jobs[] = [
                    'allow_failure' => ($item['allow_failure'] ?? false) === true,
                    'id'            => $item['id'],
                    'name'          => is_string($item['name'] ?? null) ? $item['name'] : '',
                    'stage'         => is_string($item['stage'] ?? null) ? $item['stage'] : '',
                    'status'        => is_string($item['status'] ?? null) ? $item['status'] : '',
                ];
            }
        }

        return $jobs;
    }

    /**
     * The cleaned tail of a job's log
     *
     * @param int $projectId The GitLab project ID
     * @param int $jobId     The job ID
     *
     * @return ?string Null when the log could not be read
     */
    public function tail(int $projectId, int $jobId): ?string
    {
        $log = $this->get(sprintf('%s/projects/%d/jobs/%d/trace', $this->apiUrl, $projectId, $jobId));

        return $log === null ? null : $this->clean($log);
    }

    /**
     * Keep the tail, drop control codes, mask credentials, defuse markers and mentions
     *
     * @param string $log The raw log
     *
     * @return string
     */
    private function clean(string $log): string
    {
        $tail = mb_scrub(substr($log, -self::TAIL_BYTES));
        $tail = preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $tail) ?? '';
        $tail = preg_replace('/glpat-[A-Za-z0-9_-]{10,}|gl[a-z]{2,4}-[A-Za-z0-9_-]{16,}|sk-[A-Za-z0-9_-]{16,}|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_.-]+/', '[masked]', $tail) ?? '';
        $tail = preg_replace('/(?i)\b(authorization|bearer|token|secret|password|passwd|api[_-]?key|private[_-]?key|credential\w*)(\s*[=:]\s*|\s+)\S+/', '$1$2[masked]', $tail) ?? '';
        $tail = preg_replace('#://[^/\s:@]+:[^/\s@]+@#', '://[masked]@', $tail) ?? '';
        $tail = str_replace('<!--', '<! --', $tail);

        return preg_replace('/@(?=\w)/', "@\u{200B}", $tail) ?? '';
    }

    /**
     * @param string $url The URL
     *
     * @return ?string The body, null on any failure
     */
    private function get(string $url): ?string
    {
        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));
        } catch (ClientException $e) {
            $this->logger->error('GitLab read: request failed', ['error' => $e->getMessage(), 'url' => $url]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('GitLab read: unexpected HTTP status', ['status' => $response->getStatusCode(), 'url' => $url]);

            return null;
        }

        return (string) $response->getBody();
    }
}
