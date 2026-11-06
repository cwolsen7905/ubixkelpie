<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\ProjectMode;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Enum\TriageStatus;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Service\Project\ProjectModeService;
use Psr\Log\LoggerInterface as Logger;

/**
 * Pushes on its own: every fix proposed on one MR at one head goes in one round
 *
 * Only a project in `push` mode (the maintainer switches it, per project; the default is off). When a thread job ends
 * with a stored fix, `queue()` puts one `round` job on the MR. That job waits for the MR's other thread jobs, then
 * `run()` reads the still-open proposals at the head, holds the round to its size cap and hands the fixes to the push
 * round: one commit, one plain push, `Pushed` recorded on each thread that went. A round over the cap pushes nothing and
 * says so on each thread; the proposals stay open for a thumbs-up.
 *
 * @see \Kelpie\Tests\Service\Autofix\PushRoundBatchServiceTest PHPUnit test case
 */
final class PushRoundBatchService
{
    /**
     * Constructor
     *
     * @param Logger              $logger Logger
     * @param DiscussionWriter    $writer GitLab, as the bot
     * @param PushRoundService    $round  Pushes the fixes
     * @param PushRoundCapService $cap    How big a round may be
     * @param ThreadOutcomeReader $reader The record of what Kelpie proposed
     * @param StoredEditsService  $stored Reads the stored edits
     * @param ProjectModeService  $modes  What Kelpie may do on the project
     * @param AutofixJobService   $jobs   The job queue
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
        private readonly PushRoundService $round,
        private readonly PushRoundCapService $cap,
        private readonly ThreadOutcomeReader $reader,
        private readonly StoredEditsService $stored,
        private readonly ProjectModeService $modes,
        private readonly AutofixJobService $jobs,
    ) {
    }

    /**
     * Put a round on the MR when this thread ended with a fix to push
     *
     * @param string            $projectPath  The project path with namespace
     * @param int               $mrIid        The MR IID
     * @param string            $sha          The head the thread was triaged at
     * @param string            $discussionId The thread whose job just ended
     * @param DateTimeImmutable $now          The current time
     *
     * @return bool Whether a round is queued (or was already)
     */
    public function queue(string $projectPath, int $mrIid, string $sha, string $discussionId, DateTimeImmutable $now): bool
    {
        if (!$this->modes->allows($projectPath, ProjectMode::Push)) {
            return false;
        }

        $row = $this->reader->getByThread($projectPath, $mrIid, $discussionId);
        if ($row === null || $row->outcome !== ThreadOutcome::Proposed || $row->verdict !== 'fix' || $row->headSha !== $sha || $row->editsJson === '') {
            return false;
        }

        $this->jobs->pendingJob(new AutofixJob($projectPath, $mrIid, $sha, AutofixJob::ROUND), $now);

        return true;
    }

    /**
     * Whether a thread job of the MR is still to run, so the round should wait for it
     *
     * @param string   $projectPath The project path
     * @param int      $mrIid       The MR IID
     * @param Thread[] $threads     The MR's unresolved threads
     * @param int      $roundJobId  The round's own job, never waited for
     *
     * @return bool
     */
    public function waiting(string $projectPath, int $mrIid, array $threads, int $roundJobId): bool
    {
        foreach ($threads as $thread) {
            $open = $this->jobs->reader()->getOpenJob($projectPath, $mrIid, $thread->discussionId);
            if ($open !== null && $open->getId() !== $roundJobId && $open->isOpen()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Push the round
     *
     * @param string   $project     The project ID
     * @param string   $projectPath The project path with namespace
     * @param int      $mrIid       The MR IID
     * @param string   $sha         The head the round was queued for
     * @param Thread[] $threads     The MR's unresolved threads
     * @param int      $jobId       The round job
     *
     * @return ?string Null when handled; why, when the worker should try again
     */
    public function run(string $project, string $projectPath, int $mrIid, string $sha, array $threads, int $jobId): ?string
    {
        // Checked next to the push, whatever the job says
        if (!$this->modes->allows($projectPath, ProjectMode::Push)) {
            $this->logger->info('Push round: project is not in push mode, nothing pushed', ['project' => $projectPath, 'mr_iid' => $mrIid]);

            return null;
        }

        $fixes = [];
        foreach ($threads as $thread) {
            $row = $this->reader->getByThread($projectPath, $mrIid, $thread->discussionId);
            if ($row === null || $row->outcome !== ThreadOutcome::Proposed || $row->verdict !== 'fix' || $row->headSha !== $sha || $row->editsJson === '') {
                continue;
            }

            $fixes[] = ['discussionId' => $thread->discussionId, 'summary' => $row->reason, 'edits' => $this->stored->decode($row->editsJson)];
        }
        if ($fixes === []) {
            return null;
        }

        if ($this->cap->threadsOver(count($fixes))) {
            foreach ($fixes as $fix) {
                $this->round->overCap($project, $mrIid, $fix['discussionId'], $this->cap->notice(count($fixes)));
            }

            return null;
        }

        $error = $this->round->run($project, $projectPath, $mrIid, $sha, $fixes, $jobId, ThreadOutcome::Pushed, $this->cap);
        if ($error !== null) {
            return $error;
        }

        foreach ($fixes as $fix) {
            if ($this->reader->getByThread($projectPath, $mrIid, $fix['discussionId'])?->outcome === ThreadOutcome::Pushed) {
                // Some thread went: the MR says so (a failed one has its own reply)
                $this->writer->changeLabels($project, $mrIid, [TriageStatus::Pushed->value], [TriageStatus::Working->value, TriageStatus::Proposed->value]);

                break;
            }
        }

        return null;
    }
}
