<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Service\Triage\TriageService;
use Psr\Log\LoggerInterface as Logger;

/**
 * Triage the unresolved threads of an MR and reply or label on GitLab; changes no code
 *
 * Only for allow-listed projects, written as the kelpie-bot user. `kelpie:respond` parses its input
 * and prints what this returns.
 *
 * @see \Kelpie\Tests\Service\Autofix\RespondServiceTest PHPUnit test case
 */
final class RespondService
{
    /**
     * Constructor
     *
     * @param Logger              $logger        Logger
     * @param GitlabClientFactory $gitlab        The GitLab client factory
     * @param TriageService       $triageService The triage service
     * @param AutofixSettings     $settings      The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly TriageService $triageService,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * Respond to an MR's threads
     *
     * @param string  $mr      The merge request IID
     * @param ?string $project The GitLab project ID, or null for the settings' default
     * @param ?string $path    The project path with namespace, or null for the settings' default
     *
     * @return RunResult
     */
    public function respond(string $mr, ?string $project = null, ?string $path = null): RunResult
    {
        $project ??= $this->settings->ciProjectId;
        $path    ??= $this->settings->ciProjectPath;

        if (!ctype_digit($mr) || !ctype_digit($project)) {
            return new RunResult(false, ['The MR IID and the project ID (--project or CI_PROJECT_ID) must be numbers.'], true);
        }

        if ($this->settings->apiUrl === '' || $this->settings->botToken === '') {
            return new RunResult(false, ['GITLAB_API_URL and KELPIE_BOT_GITLAB_TOKEN must be set.'], true);
        }

        if (!in_array($path, $this->settings->projects, true)) {
            return new RunResult(false, ['The project path (--path or CI_PROJECT_PATH) is not in KELPIE_AUTOFIX_PROJECTS.'], true);
        }

        $writer = $this->gitlab->writer();
        $sha    = $writer->headSha($project, (int) $mr);
        if ($sha === null) {
            return new RunResult(false, ['Could not read the merge request.'], true);
        }

        $threads   = $this->gitlab->reader()->read((int) $project, (int) $mr);
        $responder = new ThreadResponderService(
            $this->logger,
            $writer,
            new ClaimService($this->logger, $writer, $this->settings->claimTtl),
            new ReplyService($this->logger, $writer),
            $this->triageService,
        );

        $lines = [];
        foreach ($responder->respond($project, (int) $mr, $sha, $threads) as $discussionId => $outcome) {
            $lines[] = sprintf('%s  %s', $discussionId, $outcome->value);
        }

        return new RunResult(true, $lines === [] ? ['No unresolved threads.'] : $lines);
    }
}
