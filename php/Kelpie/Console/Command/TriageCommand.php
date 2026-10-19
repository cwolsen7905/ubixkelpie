<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\Service\Triage\TriageDryRunService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Dry run: triage every unresolved thread of a merge request and print the verdicts
 *
 * Read-only. Reads the MR's discussions from GitLab (`GITLAB_API_URL`, `GITLAB_API_TOKEN`) and asks
 * the model (`OPENROUTER_API_KEY`, optional `KELPIE_TRIAGE_MODEL`); it writes nothing anywhere.
 *
 * Does not extend the uBixCore command base: that base requires a `Ubix\Console\Command\` class name,
 * so a host command cannot use it. Discovery only needs a Symfony command.
 *
 * @see \Kelpie\Tests\Console\Command\TriageCommandTest PHPUnit test case
 */
final class TriageCommand extends RunCommand
{
    /**
     * Constructor
     *
     * @param Logger              $logger              Logger
     * @param TriageDryRunService $triageDryRunService Classifies the threads
     */
    public function __construct(
        Logger $logger,
        private readonly TriageDryRunService $triageDryRunService,
    ) {
        parent::__construct($logger, 'kelpie:triage');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Dry run: classify the unresolved threads of a merge request (writes nothing)')
            ->addArgument('mr', InputArgument::REQUIRED, 'The merge request IID')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The GitLab project ID (default: CI_PROJECT_ID)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        return $this->report($output, $this->triageDryRunService->triage($this->argumentString($input, 'mr'), $this->optionOrNull($input, 'project')));
    }
}
