<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Policy\EventFilter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Sweep the open MRs of the allow-listed projects for unresolved threads and failed pipelines
 *
 * Wires the sweep from the settings: the event filter, one fixer runner per project and the GitLab readers.
 * Reports only unless autofix is enabled. `kelpie:sweep` parses its input and prints what this returns.
 *
 * @see \Kelpie\Tests\Service\Autofix\SweepRunServiceTest PHPUnit test case
 */
final class SweepRunService
{
    /**
     * Constructor
     *
     * @param Logger                    $logger   Logger
     * @param GitlabClientFactory       $gitlab   The GitLab client factory
     * @param FixerRunnerBuilderService $runners  The fixer runner factory
     * @param AutofixJobService         $jobs     The job table
     * @param AutofixSettings           $settings The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly FixerRunnerBuilderService $runners,
        private readonly AutofixJobService $jobs,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * Sweep
     *
     * @param ?string $maxRuns The `--max-runs` option as given, or null for the settings' default
     *
     * @return RunResult
     */
    public function sweep(?string $maxRuns = null): RunResult
    {
        $maxRuns = $maxRuns !== null && ctype_digit($maxRuns) ? (int) $maxRuns : $this->settings->sweepMaxRuns;

        if ($this->settings->apiUrl === '' || $this->settings->botToken === '') {
            return new RunResult(false, ['GITLAB_API_URL and KELPIE_BOT_GITLAB_TOKEN must be set.'], true);
        }

        $projects = $this->settings->projects;
        $filter   = new EventFilter(
            $this->logger,
            $this->settings->botUsername,
            $this->settings->skipAuthors,
            $projects,
            skipBranches: $this->settings->skipBranches,
        );

        $runners = [];
        foreach ($projects as $path) {
            $runners[$path] = $this->runners->make($this->settings->enabled, $path);
        }

        $service = new SweepService(
            $this->logger,
            $this->settings->enabled,
            $projects,
            max(1, $maxRuns),
            new EnqueueService($this->logger, $filter),
            $this->gitlab->lister(),
            $this->gitlab->reader(),
            $this->gitlab->writer(),
            $this->gitlab->jobLogReader(),
            $this->jobs,
            $runners,
        );

        $lines = $service->sweep(new DateTimeImmutable());

        return new RunResult(true, $lines === [] ? ['Nothing needs resolution.'] : $lines);
    }
}
