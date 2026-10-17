<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use DateTimeImmutable;
use Kelpie\Agent\ClaudeCliFixerAgent;
use Kelpie\Agent\FixerAgentInterface as FixerAgent;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Enum\FixOutcome;
use Kelpie\GitLab\DiscussionReader;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\HttpClient\BotHttpClient;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\ClaimService;
use Kelpie\Service\Autofix\FixerRunnerService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Policy\SafetyPolicy;
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
 * Fix one review thread of an MR: sandboxed checkout, agent, the repo's gate, a non-force push
 *
 * Does nothing unless `KELPIE_AUTOFIX_ENABLED=true`. Only allow-listed projects
 * (`KELPIE_AUTOFIX_PROJECTS`, default `ubixsys/ubixkelpie`). It writes as the kelpie-bot user
 * (`KELPIE_BOT_GITLAB_TOKEN`) and pushes to the MR's own source branch only. Run by hand: no worker
 * calls it yet.
 *
 * @see \Kelpie\Tests\Console\Command\FixCommandTest PHPUnit test case
 */
final class FixCommand extends SymfonyCommand
{
    /**
     * Constructor
     *
     * @param Logger            $logger         Logger
     * @param HttpClient        $httpClient     The HTTP client
     * @param RequestFactory    $requestFactory PSR-17 request factory
     * @param StreamFactory     $streamFactory  PSR-17 stream factory
     * @param JsonService       $jsonService    JSON service
     * @param TriageService     $triageService  Classifies one thread
     * @param AutofixJobService $jobs           The job state
     * @param ?ProcessRunner    $processRunner  The process runner (the real one when null)
     * @param ?FixerAgent       $agent          The fixer agent (the claude CLI when null)
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly JsonService $jsonService,
        private readonly TriageService $triageService,
        private readonly AutofixJobService $jobs,
        private readonly ?ProcessRunner $processRunner = null,
        private readonly ?FixerAgent $agent = null,
    ) {
        parent::__construct('kelpie:fix');
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
        $mr         = $input->getArgument('mr');
        $discussion = $input->getArgument('discussion');
        $project    = $input->getOption('project');
        $project    = is_string($project) ? $project : (string) getenv('CI_PROJECT_ID');
        $path       = $input->getOption('path');
        $path       = is_string($path) ? $path : (string) getenv('CI_PROJECT_PATH');
        $apiUrl     = rtrim((string) getenv('GITLAB_API_URL'), '/');
        $token      = (string) getenv('KELPIE_BOT_GITLAB_TOKEN');

        if (getenv('KELPIE_AUTOFIX_ENABLED') !== 'true') {
            $output->writeln('Autofix is disabled (KELPIE_AUTOFIX_ENABLED is not "true"); nothing done.');

            return SymfonyCommand::SUCCESS;
        }

        if (!is_string($mr) || !ctype_digit($mr) || !is_string($discussion) || preg_match('/^[0-9a-f]{8,64}$/', $discussion) !== 1 || !ctype_digit($project)) {
            $output->writeln('<error>The MR IID and project ID must be numbers and the discussion ID a hex string.</error>');

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

        $client = new BotHttpClient($this->logger, $this->httpClient, $token);
        $writer = new DiscussionWriter($this->logger, $client, $this->requestFactory, $this->streamFactory, $apiUrl, $this->jsonService);
        $sha    = $writer->headSha($project, (int) $mr);
        if ($sha === null) {
            $output->writeln('<error>Could not read the merge request.</error>');

            return SymfonyCommand::FAILURE;
        }

        $thread = null;
        foreach ((new DiscussionReader($this->logger, $client, $this->requestFactory, $apiUrl, $this->jsonService))->read((int) $project, (int) $mr) as $candidate) {
            if ($candidate->discussionId === $discussion) {
                $thread = $candidate;
            }
        }
        if ($thread === null) {
            $output->writeln('<error>No unresolved thread with that ID.</error>');

            return SymfonyCommand::FAILURE;
        }

        // The job row: coalescing and the caps decide whether there is anything to run.
        [$enqueue, $job] = $this->jobs->pendingJob(new AutofixJob($path, (int) $mr, $sha, 'note', $discussion), new DateTimeImmutable());

        if ($job === null || $job->getId() === null) {
            $output->writeln(sprintf('%s  not run (%s)', $discussion, $enqueue->value));

            return SymfonyCommand::SUCCESS;
        }

        $runner  = $this->processRunner ?? new ProcOpenProcessRunner($this->logger);
        $gitBase = rtrim((string) (getenv('KELPIE_GIT_BASE_URL') ?: preg_replace('#/api/v\d+$#', '', $apiUrl)), '/');
        $service = new FixerRunnerService(
            $this->logger,
            true,
            $writer,
            new ClaimService($this->logger, $writer, (int) (getenv('KELPIE_CLAIM_TTL_SECONDS') ?: 1800)),
            new ReplyService($this->logger, $writer),
            $this->triageService,
            new SafetyPolicy($this->logger),
            $this->agent ?? new ClaudeCliFixerAgent(
                $this->logger,
                $runner,
                (string) getenv('OPENROUTER_API_KEY'),
                (string) (getenv('KELPIE_FIXER_MODEL') ?: 'anthropic/claude-sonnet-5.5'),
            ),
            new GateRunner($this->logger, $runner, (string) getenv('COMPOSER_AUTH')),
            new Checkout($this->logger, $runner, $token),
            $this->jobs->reader(),
            $this->jobs->writer(),
            $gitBase . '/' . $path . '.git',
        );

        $outcome = $service->run($project, $path, (int) $mr, $thread, $sha, $job->getId());
        $output->writeln(sprintf('%s  %s', $discussion, $outcome->value));

        return $outcome === FixOutcome::Failed ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
