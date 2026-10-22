<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Builds the reaction service for one project (the settings and wiring that only the worker has)
 *
 * @see \Kelpie\Tests\Service\Autofix\ProposalReactionBuilderServiceTest PHPUnit test case
 */
final class ProposalReactionBuilderService
{
    /**
     * Constructor
     *
     * @param Logger              $logger        Logger
     * @param GitlabClientFactory $gitlab        Makes the bot's GitLab client
     * @param AutofixJobService   $jobs          The job queue
     * @param ProcessRunner       $processRunner Runs git and the gate
     * @param ThreadOutcomeReader $reader        The record of what Kelpie proposed
     * @param ThreadOutcomeWriter $outcomes      Where the proposal's fate is written
     * @param JsonService         $json          JSON service
     * @param AutofixSettings     $settings      The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly AutofixJobService $jobs,
        private readonly ProcessRunner $processRunner,
        private readonly ThreadOutcomeReader $reader,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly JsonService $json,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * The reaction service for a project
     *
     * @param string $projectPath The project path with namespace
     *
     * @return ProposalReactionService
     */
    public function make(string $projectPath): ProposalReactionService
    {
        $writer = $this->gitlab->writer();

        return new ProposalReactionService(
            $this->logger,
            $writer,
            new ReplyService($this->logger, $writer),
            new EditApplyService($this->logger),
            new Checkout($this->logger, $this->processRunner, $this->settings->botToken),
            new GateRunner($this->logger, $this->processRunner, $this->settings->composerAuth),
            $this->reader,
            $this->outcomes,
            $this->jobs->writer(),
            $this->json,
            $this->settings->gitBaseUrl . '/' . $projectPath . '.git',
        );
    }
}
