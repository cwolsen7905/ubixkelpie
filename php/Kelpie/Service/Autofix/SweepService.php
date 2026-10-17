<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\DataTransferObject\MergeRequestSummary;
use Kelpie\DataTransferObject\Thread;
use Kelpie\GitLab\DiscussionReader;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\GitLab\JobLogReader;
use Kelpie\GitLab\MergeRequestLister;
use Kelpie\Policy\EventFilter;
use Psr\Log\LoggerInterface as Logger;

/**
 * The scheduled safety net: walk the open MRs and feed what needs resolution to the webhook's entry point
 *
 * Every unresolved thread becomes a synthetic Note Hook payload and a failed gate job of the head
 * pipeline a synthetic Pipeline Hook payload, both handed to `EnqueueService::enqueue`, so the same
 * filter and loop guards decide, and there is no second code path. A failed job's log tail is opened
 * as one MR thread (marked, so it is opened once per job), after which it is an ordinary thread for
 * triage, the fixer, the caps and the replies.
 *
 * With `$enabled` false nothing is written anywhere: candidates are only reported. With it true at most
 * `$maxRuns` threads are run per sweep.
 *
 * @see \Kelpie\Tests\Service\Autofix\SweepServiceTest PHPUnit test case
 */
final class SweepService
{
    private const string PIPELINE_MARKER = '<!-- kelpie:pipeline job=';

    /**
     * Constructor
     *
     * @param Logger               $logger     Logger
     * @param bool                 $enabled    Whether `KELPIE_AUTOFIX_ENABLED` is on
     * @param string[]             $projects   Allow-listed project paths
     * @param int                  $maxRuns    Most threads run per sweep
     * @param EnqueueService       $enqueue    The webhook's entry point
     * @param MergeRequestLister   $lister     Lists open MRs
     * @param DiscussionReader     $reader     Reads unresolved threads
     * @param DiscussionWriter     $writer     Opens the pipeline thread
     * @param JobLogReader         $logReader  Reads failed jobs and their logs
     * @param AutofixJobService    $jobs       The job state
     * @param FixerRunnerService[] $runners    The fixer by project path (the git remote differs)
     * @param string[]             $gateStages Pipeline stages whose failure is fixable
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $enabled,
        private readonly array $projects,
        private readonly int $maxRuns,
        private readonly EnqueueService $enqueue,
        private readonly MergeRequestLister $lister,
        private readonly DiscussionReader $reader,
        private readonly DiscussionWriter $writer,
        private readonly JobLogReader $logReader,
        private readonly AutofixJobService $jobs,
        private readonly array $runners,
        private readonly array $gateStages = EventFilter::GATE_STAGES,
    ) {
    }

    /**
     * Sweep every allow-listed project
     *
     * @param DateTimeImmutable $now The current time, for the daily cap
     *
     * @return list<string> One line per MR thread or pipeline job considered
     */
    public function sweep(DateTimeImmutable $now): array
    {
        $lines = [];
        $runs  = 0;

        foreach ($this->projects as $path) {
            foreach ($this->lister->open($path) as $mr) {
                foreach ($this->candidates($path, $mr) as $candidate) {
                    if (is_string($candidate)) {
                        $lines[] = $candidate;
                        continue;
                    }

                    [$job, $thread] = $candidate;
                    $label          = sprintf('%s!%d %s', $path, $mr->iid, $thread->discussionId);

                    if (!$this->enabled) {
                        $lines[] = $label . '  would run (autofix disabled)';
                        continue;
                    }
                    if ($runs >= $this->maxRuns) {
                        $lines[] = $label . '  deferred (sweep run limit)';
                        continue;
                    }

                    [$outcome, $record] = $this->jobs->pendingJob($job, $now);
                    if ($record === null || $record->getId() === null) {
                        $lines[] = $label . '  not run (' . $outcome->value . ')';
                        continue;
                    }

                    $runner = $this->runners[$path] ?? null;
                    if ($runner === null) {
                        $lines[] = $label . '  not run (no fixer for the project)';
                        continue;
                    }

                    $runs++;
                    $result  = $runner->run((string) $mr->projectId, $path, $mr->iid, $thread, $mr->sha, $record->getId());
                    $lines[] = $label . '  ' . $result->value;
                    $this->logger->info('Autofix sweep: ran', ['mr_iid' => $mr->iid, 'outcome' => $result->value, 'project_path' => $path]);
                }
            }
        }

        return $lines;
    }

    /**
     * The jobs the filter accepts for one MR, each with its thread
     *
     * @param string              $path The project path
     * @param MergeRequestSummary $mr   The MR
     *
     * @return list<array{0: AutofixJob, 1: Thread}|string> The jobs with their threads, and a report line for each skip
     */
    private function candidates(string $path, MergeRequestSummary $mr): array
    {
        $commit  = $this->lister->commit($mr->projectId, $mr->sha);
        $message = $commit['message'] ?? '';
        $threads = $this->reader->read($mr->projectId, $mr->iid);
        $found   = [];

        foreach ($threads as $thread) {
            $job = $this->enqueue->enqueue('Note Hook', $this->notePayload($path, $mr, $thread, $message));

            if (is_string($job)) {
                $found[] = sprintf('%s!%d %s  skipped: %s', $path, $mr->iid, $thread->discussionId, $job);
                continue;
            }
            // After a Kelpie commit only threads someone else opened since may be acted on (design section 3)
            if ($job->afterAutofix && ($commit === null || $thread->createdAt <= $commit['committedAt'])) {
                $found[] = sprintf('%s!%d %s  skipped: thread predates the autofix commit', $path, $mr->iid, $thread->discussionId);
                continue;
            }
            $found[] = [$job, $thread];
        }

        $failed = $this->pipelineCandidate($path, $mr, $message, $threads);

        return $failed === null ? $found : [...$found, $failed];
    }

    /**
     * The thread for the head pipeline's failed gate job, opening it when it does not exist yet
     *
     * @param string              $path    The project path
     * @param MergeRequestSummary $mr      The MR
     * @param string              $message The head commit message
     * @param Thread[]            $threads The MR's unresolved threads
     *
     * @return array{0: AutofixJob, 1: Thread}|string|null The job with its thread, a report line when it was skipped, null for nothing to report
     */
    private function pipelineCandidate(string $path, MergeRequestSummary $mr, string $message, array $threads): array|string|null
    {
        if ($mr->pipelineStatus !== 'failed' || $mr->pipelineId === null) {
            return null;
        }

        $failedJobs = $this->logReader->failedJobs($mr->projectId, $mr->pipelineId);
        $gate       = null;
        foreach ($failedJobs as $failedJob) {
            if (!$failedJob['allow_failure'] && in_array($failedJob['stage'], $this->gateStages, true)) {
                $gate ??= $failedJob;
            }
        }

        $payload = [
            'builds'            => $failedJobs,
            'commit'            => ['message' => $message],
            'merge_request'     => $this->mrObject($mr, $message),
            'object_attributes' => ['sha' => $mr->sha, 'source' => 'merge_request_event', 'status' => 'failed'],
            'project'           => ['path_with_namespace' => $path],
            'user'              => ['username' => $mr->pipelineUser],
        ];
        $job     = $this->enqueue->enqueue('Pipeline Hook', $payload);

        if (is_string($job) || $gate === null) {
            return sprintf('%s!%d pipeline  skipped: %s', $path, $mr->iid, is_string($job) ? $job : 'no failed gate job');
        }

        $marker = self::PIPELINE_MARKER . $gate['id'] . ' ';
        foreach ($threads as $thread) {
            if (str_contains($thread->body, $marker)) {
                return [new AutofixJob($job->projectPath, $job->mrIid, $job->headSha, 'pipeline', $thread->discussionId), $thread];
            }
        }

        if (!$this->enabled) {
            return sprintf('%s!%d pipeline job %d  would open a thread (autofix disabled)', $path, $mr->iid, $gate['id']);
        }

        $tail = $this->logReader->tail($mr->projectId, $gate['id']);
        if ($tail === null) {
            return null;
        }

        $body = sprintf(
            "%ssha=%s -->\nThe pipeline failed in job `%s` (stage `%s`). Log tail, untrusted:\n\n```\n%s\n```",
            $marker,
            $mr->sha,
            preg_replace('/[^A-Za-z0-9_. :-]/', '_', $gate['name']) ?? '',
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $gate['stage']) ?? '',
            str_replace('```', "''\u{200B}'", $tail),
        );
        $id   = $this->writer->open((string) $mr->projectId, $mr->iid, $body);
        if ($id === null) {
            return null;
        }

        foreach ($this->reader->read($mr->projectId, $mr->iid) as $thread) {
            if ($thread->discussionId === $id) {
                return [new AutofixJob($job->projectPath, $job->mrIid, $job->headSha, 'pipeline', $id), $thread];
            }
        }

        return null;
    }

    /**
     * @param string              $path    The project path
     * @param MergeRequestSummary $mr      The MR
     * @param Thread              $thread  The thread
     * @param string              $message The head commit message
     *
     * @return array<string, mixed>
     */
    private function notePayload(string $path, MergeRequestSummary $mr, Thread $thread, string $message): array
    {
        return [
            'merge_request'     => $this->mrObject($mr, $message),
            'object_attributes' => [
                'discussion_id' => $thread->discussionId,
                'note'          => $thread->body,
                'noteable_type' => 'MergeRequest',
                'system'        => false,
                'type'          => $thread->filePath === null ? 'DiscussionNote' : 'DiffNote',
            ],
            'project'           => ['path_with_namespace' => $path],
            'user'              => ['username' => $thread->author],
        ];
    }

    /**
     * @param MergeRequestSummary $mr      The MR
     * @param string              $message The head commit message
     *
     * @return array<string, mixed>
     */
    private function mrObject(MergeRequestSummary $mr, string $message): array
    {
        return [
            'draft'         => $mr->draft,
            'iid'           => $mr->iid,
            'last_commit'   => ['id' => $mr->sha, 'message' => $message],
            'source_branch' => $mr->sourceBranch,
            'state'         => 'opened',
            'target_branch' => $mr->targetBranch,
            'title'         => $mr->title,
        ];
    }
}
