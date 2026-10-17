<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\GitLab\DiscussionReader;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\HttpClient\BotHttpClient;
use Kelpie\Service\Autofix\ClaimService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Autofix\ThreadResponderService;
use Kelpie\Service\Triage\TriageService;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Ubix\Service\JsonService;

/**
 * Triage the unresolved threads of one MR and answer them on GitLab
 *
 * Unlike `kelpie:triage` this writes: claim notes, replies and labels, as the kelpie-bot user
 * (`KELPIE_BOT_GITLAB_TOKEN`). It changes no code. Only allow-listed projects
 * (`KELPIE_AUTOFIX_PROJECTS`, default `ubixsys/ubixkelpie`).
 *
 * @see \Kelpie\Tests\Console\Command\RespondCommandTest PHPUnit test case
 */
final class RespondCommand extends SymfonyCommand
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param StreamFactory  $streamFactory  PSR-17 stream factory
     * @param JsonService    $jsonService    JSON service
     * @param TriageService  $triageService  Classifies one thread
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly JsonService $jsonService,
        private readonly TriageService $triageService,
    ) {
        parent::__construct('kelpie:respond');
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
        $mr      = $input->getArgument('mr');
        $project = $input->getOption('project');
        $project = is_string($project) ? $project : (string) getenv('CI_PROJECT_ID');
        $path    = $input->getOption('path');
        $path    = is_string($path) ? $path : (string) getenv('CI_PROJECT_PATH');
        $apiUrl  = (string) getenv('GITLAB_API_URL');
        $token   = (string) getenv('KELPIE_BOT_GITLAB_TOKEN');

        if (!is_string($mr) || !ctype_digit($mr) || !ctype_digit($project)) {
            $output->writeln('<error>The MR IID and the project ID (--project or CI_PROJECT_ID) must be numbers.</error>');

            return SymfonyCommand::FAILURE;
        }

        if ($apiUrl === '' || $token === '') {
            $output->writeln('<error>GITLAB_API_URL and KELPIE_BOT_GITLAB_TOKEN must be set.</error>');

            return SymfonyCommand::FAILURE;
        }

        $allowed = array_map('trim', explode(',', getenv('KELPIE_AUTOFIX_PROJECTS') ?: 'ubixsys/ubixkelpie'));
        if (!in_array($path, $allowed, true)) {
            $output->writeln('<error>The project path (--path or CI_PROJECT_PATH) is not in KELPIE_AUTOFIX_PROJECTS.</error>');

            return SymfonyCommand::FAILURE;
        }

        $apiUrl = rtrim($apiUrl, '/');
        $client = new BotHttpClient($this->logger, $this->httpClient, $token);
        $writer = new DiscussionWriter($this->logger, $client, $this->requestFactory, $this->streamFactory, $apiUrl, $this->jsonService);
        $sha    = $writer->headSha($project, (int) $mr);

        if ($sha === null) {
            $output->writeln('<error>Could not read the merge request.</error>');

            return SymfonyCommand::FAILURE;
        }

        $threads   = (new DiscussionReader($this->logger, $client, $this->requestFactory, $apiUrl, $this->jsonService))->read((int) $project, (int) $mr);
        $responder = new ThreadResponderService(
            $this->logger,
            $writer,
            new ClaimService($this->logger, $writer, (int) (getenv('KELPIE_CLAIM_TTL_SECONDS') ?: 1800)),
            new ReplyService($this->logger, $writer),
            $this->triageService,
        );

        $outcomes = $responder->respond($project, (int) $mr, $sha, $threads);
        if ($outcomes === []) {
            $output->writeln('No unresolved threads.');
        }
        foreach ($outcomes as $discussionId => $outcome) {
            $output->writeln(sprintf('%s  %s', $discussionId, $outcome->value));
        }

        return SymfonyCommand::SUCCESS;
    }
}
