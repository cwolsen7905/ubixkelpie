<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\Service\Autofix\RespondService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Triage the unresolved threads of one MR and answer them on GitLab
 *
 * Unlike `kelpie:triage` this writes: claim notes, replies and labels, as the kelpie-bot user
 * (`KELPIE_BOT_GITLAB_TOKEN`). It changes no code. Only allow-listed projects
 * (`KELPIE_AUTOFIX_PROJECTS`, default `ubixsys/ubixkelpie`).
 *
 * @see \Kelpie\Tests\Console\Command\RespondCommandTest PHPUnit test case
 */
final class RespondCommand extends RunCommand
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param RespondService $respondService Answers the threads
     */
    public function __construct(
        Logger $logger,
        private readonly RespondService $respondService,
    ) {
        parent::__construct($logger, 'kelpie:respond');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Triage the unresolved threads of a merge request and reply or label on GitLab (changes no code)')
            ->addArgument('mr', InputArgument::REQUIRED, 'The merge request IID')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The GitLab project ID (default: CI_PROJECT_ID)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project path with namespace (default: CI_PROJECT_PATH)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        return $this->report($output, $this->respondService->respond($this->argumentString($input, 'mr'), $this->optionOrNull($input, 'project'), $this->optionOrNull($input, 'path')));
    }
}
