<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\GitLab\DiscussionReader;
use Kelpie\HttpClient\AuthenticatedHttpClient;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Classify the unresolved threads of an MR without writing anything
 *
 * Reads with the read token (`GITLAB_API_TOKEN`), not the bot's. `kelpie:triage` parses its input and
 * prints what this returns.
 *
 * @see \Kelpie\Tests\Service\Triage\TriageDryRunServiceTest PHPUnit test case
 */
final class TriageDryRunService
{
    /**
     * Constructor
     *
     * @param Logger          $logger         Logger
     * @param HttpClient      $httpClient     The HTTP client
     * @param RequestFactory  $requestFactory The PSR-17 request factory
     * @param JsonService     $jsonService    The JSON service
     * @param TriageService   $triageService  The triage service
     * @param AutofixSettings $settings       The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly JsonService $jsonService,
        private readonly TriageService $triageService,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * Classify an MR's threads
     *
     * @param string  $mr      The merge request IID
     * @param ?string $project The GitLab project ID, or null for the settings' default
     *
     * @return RunResult
     */
    public function triage(string $mr, ?string $project = null): RunResult
    {
        $project ??= $this->settings->ciProjectId;

        if (!ctype_digit($mr) || !ctype_digit($project)) {
            return new RunResult(false, ['The MR IID and the project ID (--project or CI_PROJECT_ID) must be numbers.'], true);
        }

        if ($this->settings->apiUrl === '' || $this->settings->apiToken === '') {
            return new RunResult(false, ['GITLAB_API_URL and GITLAB_API_TOKEN must be set.'], true);
        }

        $reader  = new DiscussionReader(
            $this->logger,
            new AuthenticatedHttpClient($this->logger, $this->httpClient, $this->settings->apiToken),
            $this->requestFactory,
            $this->settings->apiUrl,
            $this->jsonService,
        );
        $threads = $reader->read((int) $project, (int) $mr);

        if ($threads === []) {
            return new RunResult(true, ['No unresolved threads.']);
        }

        $lines = [];
        foreach ($threads as $thread) {
            $verdict  = $this->triageService->triage($thread);
            $location = $thread->filePath === null ? 'general' : $thread->filePath . ($thread->line === null ? '' : ':' . $thread->line);

            $lines[] = sprintf('%s  %s  [%s]', $thread->discussionId, $location, $verdict->class->value);
            $lines[] = '    ' . $verdict->reason;
        }

        return new RunResult(true, $lines);
    }
}
