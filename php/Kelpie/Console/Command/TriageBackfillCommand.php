<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\Service\Triage\TriageBackfillService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Queue the open issues that predate triage so the worker triages them
 *
 * A dry run by default. It queues jobs only; the model is called and the comment and label are posted by
 * `kelpie:work`.
 *
 * @see \Kelpie\Tests\Console\Command\TriageBackfillCommandTest PHPUnit test case
 */
final class TriageBackfillCommand extends RunCommand
{
    /**
     * Constructor
     *
     * @param Logger                $logger                Logger
     * @param TriageBackfillService $triageBackfillService Lists and queues the issues
     */
    public function __construct(
        Logger $logger,
        private readonly TriageBackfillService $triageBackfillService,
    ) {
        parent::__construct($logger, 'kelpie:triage-backfill');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Queue open issues that have no kelpie:: label for the triage worker (a dry run unless --no-dry-run)')
            ->addOption('dry-run', null, InputOption::VALUE_NEGATABLE, 'Only list what would be queued (the default); --no-dry-run queues it', true)
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Only this project path (must be in KELPIE_AUTOFIX_PROJECTS)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Most issues to queue in this run (default 20)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        return $this->report($output, $this->triageBackfillService->backfill($this->optionOrNull($input, 'project'), $this->optionOrNull($input, 'limit'), $input->getOption('dry-run') !== false));
    }
}
