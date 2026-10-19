<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\OpenIssue;
use Kelpie\DataTransferObject\RunResult;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Queues the issues that were open before triage existed
 *
 * Lists the open issues of the enrolled projects and queues one triage job (the same write the webhook
 * makes) for each that has no `kelpie::*` label, is not confidential, not by the bot or a skipped author,
 * and is not labelled `kelpie:skip`. It never calls the model and never posts: the worker does that. An
 * issue that already has a job is left alone and does not count against the limit, so a re-run moves on
 * to the next issues instead of repeating the same ones. A dry run lists and writes nothing.
 *
 * @see \Kelpie\Tests\Service\Triage\TriageBackfillServiceTest PHPUnit test case
 */
final class TriageBackfillService
{
    public const int DEFAULT_LIMIT = 20;

    /**
     * Constructor
     *
     * @param Logger               $logger   Logger
     * @param GitlabClientFactory  $gitlab   Builds the issue client from the settings
     * @param IssueTriageJobReader $reader   Finds an issue's existing job
     * @param IssueTriageJobWriter $writer   Queues a job
     * @param AutofixSettings      $settings The settings: flags, project list, bot
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly IssueTriageJobReader $reader,
        private readonly IssueTriageJobWriter $writer,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * Queue (or, in a dry run, list) the issues that need triage
     *
     * @param ?string $project The one project to backfill; all enrolled projects when null
     * @param ?string $limit   Most jobs to queue in this run (default 20)
     * @param bool    $dryRun  Only list what would be queued
     *
     * @return RunResult
     */
    public function backfill(?string $project = null, ?string $limit = null, bool $dryRun = true): RunResult
    {
        if ($limit !== null && (!ctype_digit($limit) || (int) $limit < 1)) {
            return new RunResult(false, ['--limit must be a positive whole number.'], true);
        }
        $remaining = $limit === null ? self::DEFAULT_LIMIT : (int) $limit;

        if ($this->settings->apiUrl === '' || $this->settings->botToken === '') {
            return new RunResult(false, ['GITLAB_API_URL and KELPIE_BOT_GITLAB_TOKEN must be set.'], true);
        }
        if ($project !== null && !in_array($project, $this->settings->projects, true)) {
            return new RunResult(false, ['The project ' . $project . ' is not in KELPIE_AUTOFIX_PROJECTS.'], true);
        }
        if (!$dryRun && !$this->settings->enabled) {
            return new RunResult(false, ['Autofix is disabled (KELPIE_AUTOFIX_ENABLED is not "true"); nothing queued. A dry run still works.'], true);
        }

        $client = $this->gitlab->issueClient();
        $lines  = [];
        $ok     = true;
        $count  = 0;

        foreach ($project === null ? $this->settings->projects : [$project] as $path) {
            $issues = $client->openIssues($path);
            if ($issues === null) {
                $lines[] = $path . ': the open issues could not be read.';
                $ok      = false;
                continue;
            }

            foreach ($issues as $issue) {
                if ($this->skipReason($issue) !== null || $this->reader->getJobForIssue($path, $issue->iid) !== null) {
                    continue;
                }
                if ($count >= $remaining) {
                    $lines[] = $path . ': stopped at the limit of ' . $remaining . '; run again for the rest.';
                    break 2;
                }

                if (!$dryRun && !$this->queue($path, $issue->iid)) {
                    $lines[] = $path . '#' . $issue->iid . ': could not be queued.';
                    $ok      = false;
                    continue;
                }

                ++$count;
                $lines[] = ($dryRun ? 'would queue ' : 'queued ') . $path . '#' . $issue->iid;
            }
        }

        $lines[] = ($dryRun ? 'Dry run: ' . $count . ' issue(s) would be queued; nothing was written. Add --no-dry-run to queue them.' : $count . ' issue(s) queued for the worker.');

        return new RunResult($ok, $lines, !$ok);
    }

    /**
     * Why an open issue is left out, or null when it needs triage
     *
     * @param OpenIssue $issue The issue
     *
     * @return ?string
     */
    private function skipReason(OpenIssue $issue): ?string
    {
        if ($issue->confidential) {
            return 'confidential';
        }
        if ($issue->author === $this->settings->botUsername || in_array($issue->author, $this->settings->skipAuthors, true)) {
            return 'bot or skipped author';
        }
        if (in_array(IssueTriageService::SKIP_LABEL, $issue->labels, true)) {
            return 'opted out';
        }
        foreach ($issue->labels as $label) {
            if (str_starts_with($label, 'kelpie::')) {
                return 'already has a kelpie label';
            }
        }

        return null;
    }

    /**
     * @param string $path The project path
     * @param int    $iid  The issue number
     *
     * @return bool Whether the job was queued
     */
    private function queue(string $path, int $iid): bool
    {
        try {
            $this->writer->enqueue($path, $iid);
        } catch (Throwable $e) {
            $this->logger->error('Triage backfill: issue could not be queued', ['error' => $e->getMessage(), 'issue_iid' => $iid, 'project_path' => $path]);

            return false;
        }

        return true;
    }
}
