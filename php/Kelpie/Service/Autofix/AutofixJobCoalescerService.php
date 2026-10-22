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

        // A fan-out job runs nothing itself; the caps bind the thread jobs it creates
        $exceeded = $input->discussionId === null ? null : $this->exceededCap(
            $this->reader->countFixCommitsForMr($input->projectPath, $input->mrIid),
            $this->reader->countAttemptsForThread($input->projectPath, $input->mrIid, $threadKey),
            $this->reader->countJobsSince($input->projectPath, $now->modify('-1 day')),
        );

        if ($exceeded !== null) {
            $this->logger->warning('Autofix job over cap', ['cap' => $exceeded, 'mr_iid' => $input->mrIid, 'project_path' => $input->projectPath]);

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
     * The key of the one open job a job coalesces with
     *
     * The discussion id; '' for an MR-level job; for a review job, which does not know its MR yet, the head sha.
     *
     * @param AutofixJob $job The job
     *
     * @return string
     */
    public function threadKey(AutofixJob $job): string
    {
        return $job->discussionId ?? ($job->source === AutofixJob::REVIEW ? 'review-' . $job->headSha : '');
    }

    /**
     * Which cap, if any, a new job would break
     *
     * @param int $commitsForMr     Fix commits already on the MR
     * @param int $attemptsOnThread Attempts already made on the thread
     * @param int $jobsToday        Jobs the project created in the last 24 hours
     *
     * @return ?string The cap name, or null when within the caps
     */
    private function exceededCap(int $commitsForMr, int $attemptsOnThread, int $jobsToday): ?string
    {
        return match (true) {
            $commitsForMr >= $this->caps->commitsPerMr          => 'commits_per_mr',
            $attemptsOnThread >= $this->caps->attemptsPerThread => 'attempts_per_thread',
            $jobsToday >= $this->caps->jobsPerDay               => 'jobs_per_day',
            default                                             => null,
        };
    }
}
