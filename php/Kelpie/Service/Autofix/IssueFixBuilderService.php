<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Service\Spend\SpendCapService;
use Psr\Log\LoggerInterface as Logger;

/**
 * Builds the issue fix service: its own checkout and gate over the shared models, caps and project table
 *
 * @see \Kelpie\Tests\Service\Autofix\IssueFixBuilderServiceTest PHPUnit test case
 */
final class IssueFixBuilderService
{
    /**
     * Constructor
     *
     * @param Logger                   $logger        Logger
     * @param GitlabClientFactory      $gitlab        The GitLab client factory
     * @param IssueProposeEditsService $propose       Asks the propose model for edits
     * @param ProcessRunner            $processRunner The process runner
     * @param GateExecutor             $gateExecutor  Where the gate's steps run (here, or the gate pod)
     * @param ProjectReader            $projects      The projects
     * @param SpendCapService          $spendCaps     The daily spend caps
     * @param ThreadOutcomeWriter      $outcomes      The outcome record
     * @param AutofixSettings          $settings      The settings
     * @param ?string                  $reviewer      Username asked to review the merge requests; null reads KELPIE_FIX_REVIEWER, empty means none
     * @param ?string                  $gateLabel     The label a Developer adds to ask for a fix; null reads KELPIE_AUTOFIX_GATE_LABEL, default `kelpie::fix`
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly IssueProposeEditsService $propose,
        private readonly ProcessRunner $processRunner,
        private readonly GateExecutor $gateExecutor,
        private readonly ProjectReader $projects,
        private readonly SpendCapService $spendCaps,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly AutofixSettings $settings,
        private readonly ?string $reviewer = null,
        private readonly ?string $gateLabel = null,
    ) {
    }

    /**
     * The service
     *
     * @return IssueFixService
     */
    public function make(): IssueFixService
    {
        return new IssueFixService(
            $this->logger,
            $this->gitlab,
            $this->propose,
            new EditApplyService($this->logger),
            new SafetyPolicy($this->logger),
            new IssueGateService($this->logger, new GateRunner($this->logger, $this->processRunner, $this->gateExecutor, $this->settings->composerAuth)),
            new Checkout($this->logger, $this->processRunner, $this->settings->botToken),
            $this->spendCaps,
            $this->projects,
            $this->outcomes,
            $this->settings,
            $this->reviewer ?? (string) getenv('KELPIE_FIX_REVIEWER'),
            $this->gateLabel ?? ((string) getenv('KELPIE_AUTOFIX_GATE_LABEL') ?: 'kelpie::fix'),
        );
    }
}
