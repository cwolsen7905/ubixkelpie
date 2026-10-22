<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\Service\Autofix\FixThreadService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Fix one review thread of an MR: sandboxed checkout, agent, the repo's gate, a non-force push
 *
 * Does nothing unless `KELPIE_AUTOFIX_ENABLED=true`. Only projects whose mode in
 * `projects` is `propose` or `push`. It writes as the kelpie-bot user
 * (`KELPIE_BOT_GITLAB_TOKEN`) and pushes to the MR's own source branch only. Run by hand: no worker
 * calls it yet.
 *
 * @see \Kelpie\Tests\Console\Command\FixCommandTest PHPUnit test case
 */
final class FixCommand extends RunCommand
{
    /**
     * Constructor
     *
     * @param Logger           $logger           Logger
     * @param FixThreadService $fixThreadService Fixes one thread
     */
    public function __construct(
        Logger $logger,
        private readonly FixThreadService $fixThreadService,
    ) {
        parent::__construct($logger, 'kelpie:fix');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Fix one review thread of a merge request and push to its source branch (needs KELPIE_AUTOFIX_ENABLED=true)')
            ->addArgument('mr', InputArgument::REQUIRED, 'The merge request IID')
            ->addArgument('discussion', InputArgument::REQUIRED, 'The discussion ID of the thread')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The GitLab project ID (default: CI_PROJECT_ID)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project path with namespace (default: CI_PROJECT_PATH)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        return $this->report($output, $this->fixThreadService->fix($this->argumentString($input, 'mr'), $this->argumentString($input, 'discussion'), $this->optionOrNull($input, 'project'), $this->optionOrNull($input, 'path')));
    }
}
