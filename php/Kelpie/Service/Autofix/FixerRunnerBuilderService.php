<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageService;
use Psr\Log\LoggerInterface as Logger;

/**
 * Builds a fixer runner for one project: its own claim, reply and checkout over the shared models and job table
 *
 * @see \Kelpie\Tests\Service\Autofix\FixerRunnerBuilderServiceTest PHPUnit test case
 */
final class FixerRunnerBuilderService
{
    /**
     * Constructor
     *
     * @param Logger              $logger        Logger
     * @param GitlabClientFactory $gitlab        The GitLab client factory
     * @param TriageService       $triageService The triage service
     * @param AutofixJobService   $jobs          The job table
     * @param ProcessRunner       $processRunner The process runner
     * @param ProposeEditsService $propose       Asks the propose model for edits
     * @param ThreadOutcomeWriter $outcomes      The outcome record
     * @param AutofixSettings     $settings      The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly TriageService $triageService,
        private readonly AutofixJobService $jobs,
        private readonly ProcessRunner $processRunner,
        private readonly ProposeEditsService $propose,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * The runner for a project
     *
     * @param bool   $enabled     Whether the runner may act (otherwise it only reports)
     * @param string $projectPath The project path with namespace
     *
     * @return FixerRunnerService
     */
    public function make(bool $enabled, string $projectPath): FixerRunnerService
    {
        $writer = $this->gitlab->writer();

        return new FixerRunnerService(
            $this->logger,
            $enabled,
            $writer,
            new ClaimService($this->logger, $writer, $this->settings->claimTtl),
            new ReplyService($this->logger, $writer),
            $this->triageService,
            $this->propose,
            new EditApplyService($this->logger),
            new TriageStatusService($this->logger, $writer),
            new SafetyPolicy($this->logger),
            new LlmFamilyService($this->logger),
            new Checkout($this->logger, $this->processRunner, $this->settings->botToken),
            $this->jobs->reader(),
            $this->jobs->writer(),
            $this->outcomes,
            $this->settings->gitBaseUrl . '/' . $projectPath . '.git',
        );
    }
}
