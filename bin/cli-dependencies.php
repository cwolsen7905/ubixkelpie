<?php

declare(strict_types=1);

use DI\Container;
use Kelpie\Agent\ClaudeCliFixerAgent;
use Kelpie\Agent\FixerAgentInterface as FixerAgent;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\OpenRouter\Review\ReviewChatClient;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobSqlRepository;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\Project\ProjectSqlRepository;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Sandbox\GateServer;
use Kelpie\Sandbox\GuardedProcessRunner;
use Kelpie\Sandbox\LocalGateExecutor;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Sandbox\RemoteGateExecutor;
use Kelpie\Service\Autofix\AutofixSettingsService;
use Ubix\HttpClient\CurlHttpClient;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Build the CLI container: the framework's own (logger, HTTP client, SQL, migrations) plus Kelpie's bindings
 *
 * The commands' services are autowired; what cannot be is bound here: the settings, read once from the
 * environment, and the interfaces with one implementation each.
 */

return static function (): Container {
    $buildFramework = require dirname(__DIR__) . '/vendor/ubixsys/ubixcore/php/Ubix/Bootstrap/cli-dependencies.php';
    assert(is_callable($buildFramework));
    $container = $buildFramework();
    assert($container instanceof Container);

    $container->set(AutofixSettings::class, factory([AutofixSettingsService::class, 'fromEnvironment']));
    $container->set(IssueTriageJobReader::class, autowire(IssueTriageJobSqlRepository::class));
    $container->set(IssueTriageJobWriter::class, autowire(IssueTriageJobSqlRepository::class));
    $container->set(ThreadOutcomeReader::class, autowire(ThreadOutcomeSqlRepository::class));
    $container->set(ThreadOutcomeWriter::class, autowire(ThreadOutcomeSqlRepository::class));
    $container->set(ProjectReader::class, autowire(ProjectSqlRepository::class));
    // Every command an unattended run starts goes through the action list; a refused one never starts (docs/security/context-scan.md)
    $container->set(ProcessRunner::class, autowire(GuardedProcessRunner::class)->constructorParameter('inner', get(ProcOpenProcessRunner::class)));
    // The gate's steps run the repository's code: in the gate pod when KELPIE_GATE_URL is set, otherwise here as before
    // (docs/security/gate-pod.md). The client's timeout covers a whole gate; KELPIE_GATE_HTTP_TIMEOUT seconds, default 3600.
    $gateUrl      = (string) getenv('KELPIE_GATE_URL');
    $gateTimeout  = (int) getenv('KELPIE_GATE_HTTP_TIMEOUT');
    $gateExecutor = autowire(LocalGateExecutor::class);
    if ($gateUrl !== '') {
        $gateExecutor = autowire(RemoteGateExecutor::class)
            ->constructorParameter('url', $gateUrl)
            ->constructorParameter('httpClient', autowire(CurlHttpClient::class)->constructorParameter('timeout', $gateTimeout > 0 ? $gateTimeout : 3600));
    }
    $container->set(GateExecutor::class, $gateExecutor);
    // The gate pod's server checks every step itself and wraps it, so it runs them with the plain runner.
    $container->set(GateServer::class, autowire(GateServer::class)->constructorParameter('runner', get(ProcOpenProcessRunner::class)));
    $container->set(
        FixerAgent::class,
        autowire(ClaudeCliFixerAgent::class)
            ->constructorParameter('apiKey', (string) getenv('OPENROUTER_API_KEY')),
    );

    // A model call takes far longer than the shared client's 5 s default (which GitLab keeps), so
    // OpenRouter gets its own client; the total is OPENROUTER_HTTP_TIMEOUT seconds, inside the review job's 15 minutes.
    // The review's tool loop talks through ReviewChatClient, not OpenRouterClient, so both get it.
    $timeout = (int) getenv('OPENROUTER_HTTP_TIMEOUT');
    foreach ([OpenRouterClient::class, ReviewChatClient::class] as $class) {
        $container->set(
            $class,
            autowire($class)
                ->constructorParameter('httpClient', autowire(CurlHttpClient::class)->constructorParameter('timeout', $timeout > 0 ? $timeout : 150)),
        );
    }

    return $container;
};
