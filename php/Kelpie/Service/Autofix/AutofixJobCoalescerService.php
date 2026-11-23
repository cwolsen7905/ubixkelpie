<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixCaps;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Enum\EnqueueOutcome;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use PDOException;
use Psr\Log\LoggerInterface as Logger;

/**
 * Coalesce incoming jobs: one open job per MR and thread, newest head sha wins, within the caps
 *
 * Each cap has a soft limit (the current value) and a hard limit (null is unlimited). A job that would pass a soft
 * limit is still enqueued (the caller warns); a job that would break a hard limit is refused.
 *
 * @see \Kelpie\Tests\Service\Autofix\AutofixJobCoalescerServiceTest PHPUnit test case
 */
final class AutofixJobCoalescerService
{
    /**
     * Constructor
     *
     * @param Logger           $logger Logger
     * @param AutofixJobReader $reader The job reader
     * @param AutofixJobWriter $writer The job writer
     * @param AutofixCaps      $caps   The caps
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly AutofixJobReader $reader,
        private readonly AutofixJobWriter $writer,
        private readonly AutofixCaps $caps,
    ) {
    }

    /**
     * Enqueue a job unless one is already open for the MR and thread, or a cap is hit
     *
     * @param AutofixJob        $input The filtered job
     * @param DateTimeImmutable $now   The current time, for the daily cap
     *
     * @return EnqueueOutcome What happened to the job
     *
     * @throws PDOException When the write fails for a reason other than the unique index
     */
    public function enqueue(AutofixJob $input, DateTimeImmutable $now): EnqueueOutcome
    {
        $threadKey = $this->threadKey($input);
        $open      = $this->reader->getOpenJob($input->projectPath, $input->mrIid, $threadKey);

        if ($open instanceof AutofixJobRecord) {
            // Same head, or a run already in flight: the open job covers it.
            // (A newer head waits for the run to finish; its event will come again.)
            if ($open->getHeadSha() === $input->headSha || $open->getStatus() !== 'pending') {
                $this->logger->info('Autofix job coalesced', ['job_id' => $open->getId(), 'thread_key' => $threadKey]);

                return EnqueueOutcome::Coalesced;
            }
        }

        // A fan-out or round job takes no thread; the caps bind the thread jobs, and the round's own caps are the push round's.
        // Only a hard cap stops the job; a soft cap is passed over (the caller warns) and the job still runs.
        $hard = $this->exceededCapFor($input, $now);

        if ($hard !== null) {
            $this->logger->warning('Autofix job over hard cap', ['cap' => $hard, 'mr_iid' => $input->mrIid, 'project_path' => $input->projectPath]);

            return EnqueueOutcome::CapExceeded;
        }

        if ($open instanceof AutofixJobRecord && $open->getId() !== null) {
            $this->writer->supersedeJob($open->getId());
        }

        try {
            $this->writer->createJob(new AutofixJobRecord(
                projectPath:  $input->projectPath,
                mrIid:        $input->mrIid,
                headSha:      $input->headSha,
                discussionId: $input->discussionId,
                threadKey:    $threadKey,
                source:       $input->source,
                afterAutofix: $input->afterAutofix,
                steering:     $input->steering,
            ));
        } catch (PDOException $exception) {
            // The unique index on open jobs lost a race: someone else enqueued first.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $this->logger->info('Autofix job coalesced by index', ['thread_key' => $threadKey]);

            return EnqueueOutcome::Coalesced;
        }

        return EnqueueOutcome::Enqueued;
    }

    /**
     * The caps
     *
     * @return AutofixCaps
     */
    public function caps(): AutofixCaps
    {
        return $this->caps;
    }

    /**
     * Which hard cap, if any, a job would break right now (the one that stops it)
     *
     * @param AutofixJob        $job The job
     * @param DateTimeImmutable $now The current time, for the daily cap
     *
     * @return ?string `commits_per_mr`, `attempts_per_thread`, `jobs_per_project` or `jobs_per_mr` (the first hard cap reached, in that order); null when no hard cap is reached or the job takes no thread
     */
    public function exceededCapFor(AutofixJob $job, DateTimeImmutable $now): ?string
    {
        if ($job->discussionId === null) {
            return null;
        }

        return $this->hardCap(
            $this->reader->countFixCommitsForMr($job->projectPath, $job->mrIid),
            $this->reader->countAttemptsForThread($job->projectPath, $job->mrIid, $this->threadKey($job)),
            $this->reader->countJobsSince($job->projectPath, $now->modify('-1 day')),
            $this->reader->countJobsForMrSince($job->projectPath, $job->mrIid, $now->modify('-1 day')),
        );
    }

    /**
     * Which soft cap, if any, a job would pass right now (warn and keep running)
     *
     * @param AutofixJob        $job The job
     * @param DateTimeImmutable $now The current time, for the daily cap
     *
     * @return ?string `commits_per_mr`, `attempts_per_thread`, `jobs_per_project` or `jobs_per_mr` (the first soft cap passed, in that order); null when within the soft caps or the job takes no thread
     */
    public function softCapExceededFor(AutofixJob $job, DateTimeImmutable $now): ?string
    {
        if ($job->discussionId === null) {
            return null;
        }

        return $this->softCap(
            $this->reader->countFixCommitsForMr($job->projectPath, $job->mrIid),
            $this->reader->countAttemptsForThread($job->projectPath, $job->mrIid, $this->threadKey($job)),
            $this->reader->countJobsSince($job->projectPath, $now->modify('-1 day')),
            $this->reader->countJobsForMrSince($job->projectPath, $job->mrIid, $now->modify('-1 day')),
        );
    }

    /**
     * The key of the one open job a job coalesces with
     *
     * The discussion id; '' for an MR-level job; for a review job, which does not know its MR yet, the head sha;
     * 'round' for the push round, of which an MR has one open at a time.
     *
     * @param AutofixJob $job The job
     *
     * @return string
     */
    public function threadKey(AutofixJob $job): string
    {
        return $job->discussionId ?? match ($job->source) {
            AutofixJob::REVIEW => 'review-' . $job->headSha,
            AutofixJob::ROUND  => 'round',
            default            => '',
        };
    }

    /**
     * Which hard cap, if any, a new job would break (a null hard limit is unlimited and never reached)
     *
     * @param int $commitsForMr     Fix commits already on the MR
     * @param int $attemptsOnThread Attempts already made on the thread
     * @param int $projectJobs      Jobs the project created in the last 24 hours, across its MRs
     * @param int $mrJobs           Jobs the MR had created in the last 24 hours
     *
     * @return ?string The hard cap name, or null when no hard cap is reached
     */
    private function hardCap(int $commitsForMr, int $attemptsOnThread, int $projectJobs, int $mrJobs): ?string
    {
        return match (true) {
            $this->caps->commitsPerMrHard !== null && $commitsForMr >= $this->caps->commitsPerMrHard          => 'commits_per_mr',
            $this->caps->attemptsPerThreadHard !== null && $attemptsOnThread >= $this->caps->attemptsPerThreadHard => 'attempts_per_thread',
            $this->caps->jobsPerProjectHard !== null && $projectJobs >= $this->caps->jobsPerProjectHard         => 'jobs_per_project',
            $this->caps->jobsPerMrHard !== null && $mrJobs >= $this->caps->jobsPerMrHard                   => 'jobs_per_mr',
            default                                                                                          => null,
        };
    }

    /**
     * Which soft cap, if any, a new job would pass
     *
     * @param int $commitsForMr     Fix commits already on the MR
     * @param int $attemptsOnThread Attempts already made on the thread
     * @param int $projectJobs      Jobs the project created in the last 24 hours, across its MRs
     * @param int $mrJobs           Jobs the MR had created in the last 24 hours
     *
     * @return ?string The soft cap name, or null when within the soft caps
     */
    private function softCap(int $commitsForMr, int $attemptsOnThread, int $projectJobs, int $mrJobs): ?string
    {
        return match (true) {
            $commitsForMr >= $this->caps->commitsPerMr          => 'commits_per_mr',
            $attemptsOnThread >= $this->caps->attemptsPerThread => 'attempts_per_thread',
            $projectJobs >= $this->caps->jobsPerProject         => 'jobs_per_project',
            $mrJobs >= $this->caps->jobsPerMr                   => 'jobs_per_mr',
            default                                             => null,
        };
    }
}
