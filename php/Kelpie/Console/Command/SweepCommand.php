<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use DateTimeImmutable;
use Kelpie\Agent\ClaudeCliFixerAgent;
use Kelpie\Agent\FixerAgentInterface as FixerAgent;
use Kelpie\GitLab\DiscussionReader;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\GitLab\JobLogReader;
use Kelpie\GitLab\MergeRequestLister;
use Kelpie\HttpClient\BotHttpClient;
use Kelpie\Policy\EventFilter;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\ClaimService;
use Kelpie\Service\Autofix\EnqueueService;
use Kelpie\Service\Autofix\FixerRunnerService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Autofix\SweepService;
use Kelpie\Service\Triage\TriageService;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Ubix\Service\JsonService;

/**
 * Sweep the open MRs of the allow-listed projects for threads and failed pipelines that need resolution
 *
 * The scheduled safety net for lost webhook deliveries (design section 1), run every 15 minutes.
 * With `KELPIE_AUTOFIX_ENABLED` off it only reads and reports what it would do. With it on it runs
 * up to `KELPIE_SWEEP_MAX_RUNS` (default 5) threads through the same triage and fixer as `kelpie:fix`.
 *
 * @see \Kelpie\Tests\Console\Command\SweepCommandTest PHPUnit test case
 */
final class SweepCommand extends SymfonyCommand
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
        parent::__construct('kelpie:sweep');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Sweep open MRs for unresolved threads and failed pipelines (reports only unless KELPIE_AUTOFIX_ENABLED=true)')
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Most threads to run this sweep (default: KELPIE_SWEEP_MAX_RUNS, else 5)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        $apiUrl  = rtrim((string) getenv('GITLAB_API_URL'), '/');
        $token   = (string) getenv('KELPIE_BOT_GITLAB_TOKEN');
        $enabled = getenv('KELPIE_AUTOFIX_ENABLED') === 'true';
        $maxRuns = $input->getOption('max-runs');
        $maxRuns = is_string($maxRuns) && ctype_digit($maxRuns) ? (int) $maxRuns : (int) (getenv('KELPIE_SWEEP_MAX_RUNS') ?: 5);

        if ($apiUrl === '' || $token === '') {
            $output->writeln('<error>GITLAB_API_URL and KELPIE_BOT_GITLAB_TOKEN must be set.</error>');

            return SymfonyCommand::FAILURE;
        }

        $projects = array_values(array_filter(array_map('trim', explode(',', getenv('KELPIE_AUTOFIX_PROJECTS') ?: 'ubixsys/ubixkelpie'))));
        $client   = new BotHttpClient($this->logger, $this->httpClient, $token);
        $writer   = new DiscussionWriter($this->logger, $client, $this->requestFactory, $this->streamFactory, $apiUrl, $this->jsonService);
        $runner   = $this->processRunner ?? new ProcOpenProcessRunner($this->logger);
        $gitBase  = rtrim((string) (getenv('KELPIE_GIT_BASE_URL') ?: preg_replace('#/api/v\d+$#', '', $apiUrl)), '/');

        $filter = new EventFilter(
            $this->logger,
            (string) (getenv('KELPIE_BOT_USERNAME') ?: 'kelpie-bot'),
            $this->csv('KELPIE_SKIP_AUTHORS'),
            $projects,
            skipBranches: $this->csv('KELPIE_SKIP_BRANCHES'),
        );

        $runners = [];
        foreach ($projects as $path) {
            $runners[$path] = $this->fixer($enabled, $writer, $runner, $token, $gitBase . '/' . $path . '.git');
        }

        $service = new SweepService(
            $this->logger,
            $enabled,
            $projects,
            max(1, $maxRuns),
            new EnqueueService($this->logger, $filter),
            new MergeRequestLister($this->logger, $client, $this->requestFactory, $apiUrl, $this->jsonService),
            new DiscussionReader($this->logger, $client, $this->requestFactory, $apiUrl, $this->jsonService),
            $writer,
            new JobLogReader($this->logger, $client, $this->requestFactory, $apiUrl, $this->jsonService),
            $this->jobs,
            $runners,
        );

        $lines = $service->sweep(new DateTimeImmutable());
        if ($lines === []) {
            $output->writeln('Nothing needs resolution.');
        }
        foreach ($lines as $line) {
            $output->writeln($line);
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * The fixer for one project: same triage and sandbox as kelpie:fix, with that project's git remote
     *
     * @param bool             $enabled   Whether autofix is enabled
     * @param DiscussionWriter $writer    The GitLab writer
     * @param ProcessRunner    $runner    The process runner
     * @param string           $token     The bot token
     * @param string           $remoteUrl The project's git remote
     *
     * @return FixerRunnerService
     */
    private function fixer(bool $enabled, DiscussionWriter $writer, ProcessRunner $runner, string $token, string $remoteUrl): FixerRunnerService
    {
        return new FixerRunnerService(
            $this->logger,
            $enabled,
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
            $remoteUrl,
        );
    }

    /**
     * @param string $name The environment variable
     *
     * @return string[] Its comma-separated values
     */
    private function csv(string $name): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) getenv($name)))));
    }
}
