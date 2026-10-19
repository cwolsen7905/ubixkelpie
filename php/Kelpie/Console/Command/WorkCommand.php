<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\Service\Triage\WorkRunService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Run the issue triage worker: take queued issues and post one comment and one label each
 *
 * @see \Kelpie\Tests\Console\Command\WorkCommandTest PHPUnit test case
 */
final class WorkCommand extends RunCommand
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param WorkRunService $workRunService The worker loop
     */
    public function __construct(
        Logger $logger,
        private readonly WorkRunService $workRunService,
    ) {
        parent::__construct($logger, 'kelpie:work');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Run the issue triage worker (idles unless KELPIE_AUTOFIX_ENABLED=true); exits after a bounded run so it can be restarted')
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Most jobs to take before exiting (default 200)')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Longest run in seconds before exiting (default 3600)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        return $this->report($output, $this->workRunService->run($this->optionOrNull($input, 'max-jobs'), $this->optionOrNull($input, 'max-seconds')));
    }
}
