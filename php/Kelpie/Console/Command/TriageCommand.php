<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\GitLab\DiscussionReader;
use Kelpie\Triage\AuthenticatedHttpClient;
use Kelpie\Triage\TriageService;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Ubix\Service\JsonService;

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
final class TriageCommand extends SymfonyCommand
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param JsonService    $jsonService    JSON service
     * @param TriageService  $triageService  Classifies one thread
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly JsonService $jsonService,
        private readonly TriageService $triageService,
    ) {
        parent::__construct('kelpie:triage');
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
        $mr      = $input->getArgument('mr');
        $project = $input->getOption('project');
        $project = is_string($project) ? $project : (string) getenv('CI_PROJECT_ID');
        $apiUrl  = (string) getenv('GITLAB_API_URL');
        $token   = (string) getenv('GITLAB_API_TOKEN');

        if (!is_string($mr) || !ctype_digit($mr) || !ctype_digit($project)) {
            $output->writeln('<error>The MR IID and the project ID (--project or CI_PROJECT_ID) must be numbers.</error>');

            return SymfonyCommand::FAILURE;
        }

        if ($apiUrl === '' || $token === '') {
            $output->writeln('<error>GITLAB_API_URL and GITLAB_API_TOKEN must be set.</error>');

            return SymfonyCommand::FAILURE;
        }

        $reader  = new DiscussionReader(
            $this->logger,
            new AuthenticatedHttpClient($this->logger, $this->httpClient, $token),
            $this->requestFactory,
            rtrim($apiUrl, '/'),
            $this->jsonService,
        );
        $threads = $reader->read((int) $project, (int) $mr);

        if ($threads === []) {
            $output->writeln('No unresolved threads.');

            return SymfonyCommand::SUCCESS;
        }

        foreach ($threads as $thread) {
            $verdict  = $this->triageService->triage($thread);
            $location = $thread->filePath === null ? 'general' : $thread->filePath . ($thread->line === null ? '' : ':' . $thread->line);

            $output->writeln(sprintf('%s  %s  [%s]', $thread->discussionId, $location, $verdict->class->value));
            $output->writeln('    ' . $verdict->reason);
        }

        return SymfonyCommand::SUCCESS;
    }
}
