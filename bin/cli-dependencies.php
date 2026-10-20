<?php

declare(strict_types=1);

use DI\Container;
use Kelpie\Agent\ClaudeCliFixerAgent;
use Kelpie\Agent\FixerAgentInterface as FixerAgent;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobSqlRepository;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\Project\ProjectSqlRepository;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\AutofixSettingsService;

use function DI\autowire;
use function DI\factory;

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
    $container->set(ProjectReader::class, autowire(ProjectSqlRepository::class));
    $container->set(ProcessRunner::class, autowire(ProcOpenProcessRunner::class));
    $container->set(
        FixerAgent::class,
        autowire(ClaudeCliFixerAgent::class)
            ->constructorParameter('apiKey', (string) getenv('OPENROUTER_API_KEY'))
            ->constructorParameter('model', (string) (getenv('KELPIE_FIXER_MODEL') ?: AutofixSettings::DEFAULT_MODEL)),
    );

    return $container;
};
