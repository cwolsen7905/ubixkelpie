<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\Enum\IssueFixOutcome;
use Kelpie\Service\Autofix\IssueFixBuilderService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Fix one issue now, as the worker would when the gate label is added: branch, edits, limits, gate, merge request
 *
 * Does nothing unless `KELPIE_AUTOFIX_ENABLED=true` and the project is enrolled. It does not check who added the gate
 * label (the webhook does); the issue must carry it. Run by hand to try one issue; the worker does the same for the queue.
 *
 * @see \Kelpie\Tests\Console\Command\FixIssueCommandTest PHPUnit test case
 */
final class FixIssueCommand extends RunCommand
{
    /**
     * Constructor
     *
     * @param Logger                 $logger  Logger
     * @param IssueFixBuilderService $builder Builds the issue fix service
     */
    public function __construct(
        Logger $logger,
        private readonly IssueFixBuilderService $builder,
    ) {
        parent::__construct($logger, 'kelpie:fix-issue');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Fix one issue that carries the gate label and open a merge request (needs KELPIE_AUTOFIX_ENABLED=true)')
            ->addArgument('issue', InputArgument::REQUIRED, 'The issue number in the project')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project path with namespace');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        $issue = $this->argumentString($input, 'issue');
        $path  = $this->optionOrNull($input, 'path') ?? '';
        if (!ctype_digit($issue) || (int) $issue < 1 || $path === '') {
            return $this->report($output, new RunResult(false, ['Give the issue number and --path=<namespace/project>.'], true));
        }

        $outcome = $this->builder->make()->fix($path, (int) $issue);

        return $this->report($output, new RunResult($outcome !== IssueFixOutcome::Retry, ['Issue fix: ' . $outcome->value], $outcome === IssueFixOutcome::Retry));
    }
}
