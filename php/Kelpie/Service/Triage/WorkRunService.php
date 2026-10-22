<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\IssueTriagePrompt;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\FixerRunnerBuilderService;
use Kelpie\Service\Autofix\MrJobWorkerService;
use Kelpie\Service\Autofix\ProposalReactionBuilderService;
use Kelpie\Service\Autofix\SweepRunService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * The `kelpie:work` loop: poll the queue, run what is due (merge request jobs first, then issue triage), stop after a bounded run
 *
 * The loop ends by itself (a job count or a time limit) and the Deployment restarts it, so memory
 * stays bounded and no signal handling is needed. A kill after a claim is safe: the lock expires
 * and the comment marker keeps a repeat from posting twice.
 *
 * @see \Kelpie\Tests\Service\Triage\WorkRunServiceTest PHPUnit test case
 */
final class WorkRunService
{
    public const int DEFAULT_MAX_JOBS = 200;

    public const int DEFAULT_MAX_SECONDS = 3600;

    /**
     * Constructor
     *
     * @param Logger                         $logger           Logger
     * @param GitlabClientFactory            $gitlab           Builds the issue client from the settings
     * @param IssueTriageJobReader           $reader           Reads the due jobs
     * @param IssueTriageJobWriter           $writer           Locks and settles jobs
     * @param OpenRouterClient               $openRouterClient The model client
     * @param IssueTriagePrompt              $prompt           The issue prompt
     * @param JsonService                    $jsonService      JSON service
     * @param AutofixSettings                $settings         The settings: flags, project list, tokens
     * @param AutofixJobService              $jobs             The merge request job table
     * @param SweepRunService                $sweepRun         Builds the sweep that fan-out jobs use
     * @param FixerRunnerBuilderService      $runners          The fixer runner factory
     * @param ProposalReactionBuilderService $reactions        Builds what acts on a thumbs on a proposal
     * @param int                            $pollSeconds      Seconds to sleep when nothing is due
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly IssueTriageJobReader $reader,
        private readonly IssueTriageJobWriter $writer,
        private readonly OpenRouterClient $openRouterClient,
        private readonly IssueTriagePrompt $prompt,
        private readonly JsonService $jsonService,
        private readonly AutofixSettings $settings,
        private readonly AutofixJobService $jobs,
        private readonly SweepRunService $sweepRun,
        private readonly FixerRunnerBuilderService $runners,
        private readonly ProposalReactionBuilderService $reactions,
        private readonly int $pollSeconds = 5,
    ) {
    }

    /**
     * Run until the job or time limit is reached
     *
     * @param ?string $maxJobs    Most jobs to take (default 200)
     * @param ?string $maxSeconds Longest run in seconds (default 3600)
     *
     * @return RunResult
     */
    public function run(?string $maxJobs = null, ?string $maxSeconds = null): RunResult
    {
        $jobLimit  = $maxJobs !== null && ctype_digit($maxJobs) && (int) $maxJobs > 0 ? (int) $maxJobs : self::DEFAULT_MAX_JOBS;
        $timeLimit = $maxSeconds !== null && ctype_digit($maxSeconds) && (int) $maxSeconds > 0 ? (int) $maxSeconds : self::DEFAULT_MAX_SECONDS;
        $settings  = $this->settings;

        if ($settings->apiUrl === '' || $settings->botToken === '' || $settings->openRouterKey === '') {
            return new RunResult(false, ['GITLAB_API_URL, KELPIE_BOT_GITLAB_TOKEN and OPENROUTER_API_KEY must be set.'], true);
        }

        if (!$settings->enabled) {
            $this->logger->warning('Worker: KELPIE_AUTOFIX_ENABLED is not "true"; idling, nothing is claimed');
        }

        $workerId = bin2hex(random_bytes(8));
        $mrWorker = new MrJobWorkerService(
            $this->logger,
            $this->jobs->reader(),
            $this->jobs->writer(),
            $this->gitlab->lister(),
            $this->gitlab->reader(),
            $this->sweepRun->service(max(1, $settings->sweepMaxRuns)),
            $this->runners,
            $this->reactions,
            $workerId,
        );
        $worker   = new IssueTriageWorkerService(
            $this->logger,
            $this->reader,
            $this->writer,
            new IssueTriageService($this->logger, $this->gitlab->issueClient(), $this->openRouterClient, $this->prompt, $this->jsonService, $settings->botUsername),
            $workerId,
        );

        $deadline = (new DateTimeImmutable())->modify('+' . $timeLimit . ' seconds');
        $taken    = 0;
        while ($taken < $jobLimit && new DateTimeImmutable() < $deadline) {
            $now = new DateTimeImmutable();
            if ($mrWorker->step($settings->enabled, $settings->projects, $now) || $worker->step($settings->enabled, $settings->projects, $now)) {
                ++$taken;
                continue;
            }

            sleep($this->pollSeconds);
        }

        return new RunResult(true, ['Worker stopped after ' . $taken . ' job(s).']);
    }
}
