<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\DataTransferObject\MergeRequestSummary;
use Kelpie\GitLab\DiscussionReader;
use Kelpie\GitLab\MergeRequestLister;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Take one merge request job off the table and run it
 *
 * A job is claimed with a conditional UPDATE that leases it to this worker, so of several workers only one wins and
 * a worker that dies leaves a lease that expires and the job is taken again. A failed attempt is retried after
 * one minute, then five, and the third failure is final. A job either works on one thread (the fixer runner:
 * triage, reply or fix, every step guarded by GitLab markers and the head sha, so a rerun is harmless) or
 * fans out: a finished review job or a failed pipeline has no thread yet, so the sweep's per-MR logic queues one
 * job for each thread that needs resolution.
 *
 * @see \Kelpie\Tests\Service\Autofix\MrJobWorkerServiceTest PHPUnit test case
 */
final class MrJobWorkerService
{
    /**
     * How long a worker holds a job before another may take it; a fix with its gate takes minutes
     */
    public const int LOCK_SECONDS = 1800;

    public const int MAX_ATTEMPTS = 3;

    private const array BACKOFF = [60, 300];

    /**
     * Constructor
     *
     * @param Logger                         $logger    Logger
     * @param AutofixJobReader               $reader    The job reader
     * @param AutofixJobWriter               $writer    The job writer
     * @param MergeRequestLister             $lister    Lists open MRs
     * @param DiscussionReader               $threads   Reads unresolved threads
     * @param SweepService                   $sweep     Queues the thread jobs of one MR
     * @param FixerRunnerBuilderService      $runners   The fixer runner factory
     * @param ProposalReactionBuilderService $reactions Builds what acts on a thumbs-up or thumbs-down on a proposal
     * @param string                         $workerId  Names this worker in the lease
     * @param int                            $maxQueue  Most thread jobs one fan-out may queue
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly AutofixJobReader $reader,
        private readonly AutofixJobWriter $writer,
        private readonly MergeRequestLister $lister,
        private readonly DiscussionReader $threads,
        private readonly SweepService $sweep,
        private readonly FixerRunnerBuilderService $runners,
        private readonly ProposalReactionBuilderService $reactions,
        private readonly string $workerId = 'worker',
        private readonly int $maxQueue = 20,
    ) {
    }

    /**
     * Take and run one due job
     *
     * @param bool              $enabled  Whether `KELPIE_AUTOFIX_ENABLED` is on; off, nothing is claimed
     * @param string[]          $projects Allow-listed project paths; only theirs are claimed
     * @param DateTimeImmutable $now      The current time
     *
     * @return bool Whether a job was taken, so the caller may ask again at once
     */
    public function step(bool $enabled, array $projects, DateTimeImmutable $now): bool
    {
        if (!$enabled) {
            return false;
        }

        try {
            $job = $this->claim($projects, $now);
        } catch (Throwable $e) {
            $this->logger->error('Autofix worker: claim failed', ['error' => $e->getMessage()]);

            return false;
        }
        if ($job === null || $job->getId() === null) {
            return false;
        }

        $id      = $job->getId();
        $attempt = $job->getAttempt() ?? 1;
        if ($attempt > self::MAX_ATTEMPTS) {
            // Taken over from a worker that died on its last attempt
            $this->writer->markFailed($id, 'the worker died on the last attempt');

            return true;
        }

        try {
            $error = $this->process($job, $now);
        } catch (Throwable $e) {
            $this->logger->error('Autofix worker: job threw', ['error' => $e->getMessage(), 'job_id' => $id]);
            $error = $e->getMessage();
        }

        if ($error === null) {
            $this->writer->markDone($id);

            return true;
        }
        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->logger->error('Autofix worker: giving up', ['error' => $error, 'job_id' => $id]);
            $this->writer->markFailed($id, $error);

            return true;
        }

        $this->logger->warning('Autofix worker: will retry', ['attempt' => $attempt, 'error' => $error, 'job_id' => $id]);
        $this->writer->markRetry($id, $now->modify('+' . self::BACKOFF[$attempt - 1] . ' seconds'), $error);

        return true;
    }

    /**
     * Lease the oldest due job
     *
     * @param string[]          $projects Allow-listed project paths
     * @param DateTimeImmutable $now      The current time
     *
     * @return ?AutofixJobRecord The job when this worker won the lease
     */
    private function claim(array $projects, DateTimeImmutable $now): ?AutofixJobRecord
    {
        // One past the limit, so a job whose worker died on its last attempt is offered once more and ended
        $due = $this->reader->getDueJob($projects, $now, self::MAX_ATTEMPTS + 1);
        if ($due === null || $due->getId() === null) {
            return null;
        }

        $this->writer->lockJob($due->getId(), $due->getAttempt() ?? 0, $this->workerId, $now, self::LOCK_SECONDS);

        return $this->reader->getJobLockedBy($due->getId(), $this->workerId);
    }

    /**
     * Do the job's work
     *
     * @param AutofixJobRecord  $job The leased job
     * @param DateTimeImmutable $now The current time
     *
     * @return ?string Null when the job is finished (the runner may have ended it itself), else why it must be tried again
     */
    private function process(AutofixJobRecord $job, DateTimeImmutable $now): ?string
    {
        $path = $job->getProjectPath() ?? '';
        $mrs  = $this->lister->open($path);

        if ($job->getDiscussionId() !== null) {
            return $this->work($job, $path, $mrs);
        }

        $this->fanOut($job, $path, $mrs, $now);

        return null;
    }

    /**
     * Queue the thread jobs of the MR (or, for a review job that does not know its MR, of every MR at its head)
     *
     * @param AutofixJobRecord      $job  The job
     * @param string                $path The project path
     * @param MergeRequestSummary[] $mrs  The project's open MRs
     * @param DateTimeImmutable     $now  The current time
     *
     * @return void
     */
    private function fanOut(AutofixJobRecord $job, string $path, array $mrs, DateTimeImmutable $now): void
    {
        foreach ($mrs as $mr) {
            if ($mr->sha !== $job->getHeadSha() || ($job->getMrIid() !== 0 && $mr->iid !== $job->getMrIid())) {
                continue; // Not the MR, or its head has moved on: that push raised its own event
            }

            $this->sweep->sweepMr($path, $mr, $now, $this->maxQueue);
        }
    }

    /**
     * Run the fixer for the job's thread
     *
     * @param AutofixJobRecord      $job  The job
     * @param string                $path The project path
     * @param MergeRequestSummary[] $mrs  The project's open MRs
     *
     * @return ?string
     */
    private function work(AutofixJobRecord $job, string $path, array $mrs): ?string
    {
        $id  = $job->getId() ?? 0;
        $iid = $job->getMrIid() ?? 0;
        $mr  = null;
        foreach ($mrs as $candidate) {
            if ($candidate->iid === $iid) {
                $mr = $candidate;
            }
        }
        if ($mr === null) {
            $this->logger->info('Autofix worker: MR no longer open', ['job_id' => $id]);

            return null;
        }

        if (in_array($job->getSource(), [AutofixJob::APPLY, AutofixJob::DECLINE], true)) {
            // A thumbs on a proposal: the thread need not be read, the proposal carries what is to be done
            return $this->reactions->make($path)->run((string) $mr->projectId, $path, $iid, $job->getDiscussionId() ?? '', $job->getSource() === AutofixJob::APPLY, $id);
        }

        $thread = null;
        foreach ($this->threads->read($mr->projectId, $iid) as $candidate) {
            if ($candidate->discussionId === $job->getDiscussionId()) {
                $thread = $candidate;
            }
        }
        if ($thread === null) {
            $this->logger->info('Autofix worker: thread resolved or gone', ['job_id' => $id]);

            return null;
        }

        $sha = $job->getHeadSha() ?? '';
        if ($job->getAfterAutofix() === true) {
            // After a Kelpie commit only threads someone else opened since may be acted on
            $commit = $this->lister->commit($mr->projectId, $sha);
            if ($commit === null || $thread->createdAt <= $commit['committedAt']) {
                return null;
            }
        }

        $outcome = $this->runners->make(true, $path)->run((string) $mr->projectId, $path, $iid, $thread, $sha, $id, $job->getSteering() ?? '');
        $now     = $this->reader->getJobById($id);

        // The runner ends the job itself when it has an answer; one left running is waiting (another run holds the MR)
        return $now !== null && $now->getStatus() === 'running' ? 'not run yet (' . $outcome->value . ')' : null;
    }
}
