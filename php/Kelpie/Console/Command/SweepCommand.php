<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\Service\Autofix\SweepRunService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Sweep the open MRs of the allow-listed projects for threads and failed pipelines that need resolution
 *
 * The scheduled safety net for lost webhook deliveries (design section 1), run every 15 minutes.
 * With `KELPIE_AUTOFIX_ENABLED` off it only reads and reports what it would do. With it on it queues
 * up to `KELPIE_SWEEP_MAX_RUNS` (default 5) jobs for `kelpie:work`, the one runner, which triages and fixes them.
 *
 * @see \Kelpie\Tests\Console\Command\SweepCommandTest PHPUnit test case
 */
final class SweepCommand extends RunCommand
{
    /**
     * Constructor
     *
     * @param Logger          $logger          Logger
     * @param SweepRunService $sweepRunService Runs the sweep
     */
    public function __construct(
        Logger $logger,
        private readonly SweepRunService $sweepRunService,
    ) {
        parent::__construct($logger, 'kelpie:sweep');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Sweep open MRs for unresolved threads and failed pipelines (queues jobs for kelpie:work; reports only unless KELPIE_AUTOFIX_ENABLED=true)')
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Most jobs to queue this sweep (default: KELPIE_SWEEP_MAX_RUNS, else 5)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        return $this->report($output, $this->sweepRunService->sweep($this->optionOrNull($input, 'max-runs')));
    }
}
