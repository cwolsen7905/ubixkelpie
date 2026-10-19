<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\Enum\FixOutcome;
use Kelpie\GitLab\GitlabClientFactory;
use Psr\Log\LoggerInterface as Logger;

/**
 * Fix one review thread of an MR: the checks, the job row, then the fixer runner
 *
 * Does nothing unless autofix is enabled, and only for allow-listed projects. The fixer pushes to the MR's
 * own source branch only. `kelpie:fix` parses its input and prints what this returns.
 *
 * @see \Kelpie\Tests\Service\Autofix\FixThreadServiceTest PHPUnit test case
 */
final class FixThreadService
{
    /**
     * Constructor
     *
     * @param Logger                    $logger   Logger
     * @param GitlabClientFactory       $gitlab   The GitLab client factory
     * @param AutofixJobService         $jobs     The job table
     * @param FixerRunnerBuilderService $runners  The fixer runner factory
     * @param AutofixSettings           $settings The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly AutofixJobService $jobs,
        private readonly FixerRunnerBuilderService $runners,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * Fix a thread
     *
     * @param string  $mr         The merge request IID
     * @param string  $discussion The discussion ID of the thread
     * @param ?string $project    The GitLab project ID, or null for the settings' default
     * @param ?string $path       The project path with namespace, or null for the settings' default
     *
     * @return RunResult
     */
    public function fix(string $mr, string $discussion, ?string $project = null, ?string $path = null): RunResult
    {
        $project ??= $this->settings->ciProjectId;
        $path    ??= $this->settings->ciProjectPath;

        if (!$this->settings->enabled) {
            return new RunResult(true, ['Autofix is disabled (KELPIE_AUTOFIX_ENABLED is not "true"); nothing done.']);
        }

        if (!ctype_digit($mr) || preg_match('/^[0-9a-f]{8,64}$/', $discussion) !== 1 || !ctype_digit($project)) {
            return new RunResult(false, ['The MR IID and project ID must be numbers and the discussion ID a hex string.'], true);
        }

        if ($this->settings->apiUrl === '' || $this->settings->botToken === '') {
            return new RunResult(false, ['GITLAB_API_URL and KELPIE_BOT_GITLAB_TOKEN must be set.'], true);
        }

        if (!in_array($path, $this->settings->projects, true)) {
            return new RunResult(false, ['The project path (--path or CI_PROJECT_PATH) is not in KELPIE_AUTOFIX_PROJECTS.'], true);
        }

        $writer = $this->gitlab->writer();
        $sha    = $writer->headSha($project, (int) $mr);
        if ($sha === null) {
            return new RunResult(false, ['Could not read the merge request.'], true);
        }

        $thread = null;
        foreach ($this->gitlab->reader()->read((int) $project, (int) $mr) as $candidate) {
            if ($candidate->discussionId === $discussion) {
                $thread = $candidate;
            }
        }
        if ($thread === null) {
            return new RunResult(false, ['No unresolved thread with that ID.'], true);
        }

        // The job row: coalescing and the caps decide whether there is anything to run.
        [$enqueue, $job] = $this->jobs->pendingJob(new AutofixJob($path, (int) $mr, $sha, 'note', $discussion), new DateTimeImmutable());

        if ($job === null || $job->getId() === null) {
            $this->logger->info('No fixer run for the thread', ['project' => $path, 'mr' => $mr, 'discussion' => $discussion, 'outcome' => $enqueue->value]);

            return new RunResult(true, [sprintf('%s  not run (%s)', $discussion, $enqueue->value)]);
        }

        $outcome = $this->runners->make(true, $path)->run($project, $path, (int) $mr, $thread, $sha, $job->getId());
        $line    = sprintf('%s  %s', $discussion, $outcome->value);

        return $outcome === FixOutcome::Failed ? new RunResult(false, [$line]) : new RunResult(true, [$line]);
    }
}
