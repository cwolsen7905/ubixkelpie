<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Policy\EventFilter;
use Kelpie\Service\Project\ProjectModeService;
use Psr\Log\LoggerInterface as Logger;

/**
 * Sweep the open MRs of the enrolled projects for unresolved threads and failed pipelines
 *
 * Wires the sweep from the settings: the event filter and the GitLab readers. Queues jobs for `kelpie:work` when
 * autofix is enabled, and only reports otherwise. `kelpie:sweep` parses its input and prints what this returns.
 *
 * @see \Kelpie\Tests\Service\Autofix\SweepRunServiceTest PHPUnit test case
 */
final class SweepRunService
{
    /**
     * Constructor
     *
     * @param Logger              $logger   Logger
     * @param GitlabClientFactory $gitlab   The GitLab client factory
     * @param AutofixJobService   $jobs     The job table
     * @param AutofixSettings     $settings The settings
     * @param ProjectModeService  $modes    Which projects are enrolled
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly AutofixJobService $jobs,
        private readonly AutofixSettings $settings,
        private readonly ProjectModeService $modes,
    ) {
    }

    /**
     * Sweep, queueing the jobs
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

        $lines = $this->service(max(1, $maxRuns))->sweep(new DateTimeImmutable());

        return new RunResult(true, $lines === [] ? ['Nothing needs resolution.'] : $lines);
    }

    /**
     * The sweep, wired from the settings and the projects enrolled right now
     *
     * @param int $maxRuns Most jobs queued per sweep
     *
     * @return SweepService
     */
    public function service(int $maxRuns): SweepService
    {
        $projects = $this->modes->enrolledPaths();
        $filter   = new EventFilter(
            $this->logger,
            $this->settings->botUsername,
            $this->settings->skipAuthors,
            $projects,
            skipBranches: $this->settings->skipBranches,
        );

        $writer = $this->gitlab->writer();

        return new SweepService(
            $this->logger,
            $this->settings->enabled,
            $projects,
            $maxRuns,
            new EnqueueService($this->logger, $filter, $this->jobs),
            $this->gitlab->lister(),
            $this->gitlab->reader(),
            $writer,
            $this->gitlab->jobLogReader(),
            $this->jobs,
            new ReplyService($this->logger, $writer),
        );
    }
}
