<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use DateTimeImmutable;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\Enum\IssueTriageOutcome;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Takes at most one due issue triage job and settles it
 *
 * The kill switch and the project list are checked on every step: with autofix off, or for a
 * project not on the list, nothing is claimed and the jobs stay pending. A failure is retried
 * with a growing delay up to the attempt cap, then the job is marked failed with no comment.
 *
 * @see \Kelpie\Tests\Service\Triage\IssueTriageWorkerServiceTest PHPUnit test case
 */
final class IssueTriageWorkerService
{
    public const int LOCK_SECONDS = 600;

    public const int MAX_ATTEMPTS = 3;

    /**
     * Seconds to wait after the first and second failed attempt
     */
    private const array BACKOFF = [60, 300];

    /**
     * Constructor
     *
     * @param Logger               $logger   Logger
     * @param IssueTriageJobReader $reader   Reads the due jobs
     * @param IssueTriageJobWriter $writer   Locks and settles jobs
     * @param IssueTriageService   $triage   The triage service
     * @param string               $workerId Names this worker in the job lock
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly IssueTriageJobReader $reader,
        private readonly IssueTriageJobWriter $writer,
        private readonly IssueTriageService $triage,
        private readonly string $workerId = 'worker',
    ) {
    }

    /**
     * Run one step
     *
     * @param bool              $enabled  Whether autofix is on (KELPIE_AUTOFIX_ENABLED)
     * @param string[]          $projects The enrolled projects (KELPIE_AUTOFIX_PROJECTS)
     * @param DateTimeImmutable $now      The current time
     *
     * @return bool Whether a job was taken, so the caller polls again at once
     */
    public function step(bool $enabled, array $projects, DateTimeImmutable $now): bool
    {
        if (!$enabled) {
            return false;
        }

        try {
            $job = $this->claim($projects, $now);
        } catch (Throwable $e) {
            $this->logger->error('Issue triage worker: claim failed', ['error' => $e->getMessage()]);

            return false;
        }
        if ($job === null) {
            return false;
        }

        try {
            $result = $this->triage->triage($job->projectPath, $job->issueIid);
        } catch (Throwable $e) {
            $this->logger->error('Issue triage worker: triage threw', ['error' => $e->getMessage(), 'job_id' => $job->id]);
            $result = null;
        }

        if ($result !== null && $result->outcome !== IssueTriageOutcome::Failed) {
            $this->writer->markDone($job->id, $result->class?->value);

            return true;
        }

        $error = $result->detail ?? 'unexpected error';
        if ($job->attempt >= self::MAX_ATTEMPTS) {
            $this->logger->error('Issue triage worker: giving up', ['error' => $error, 'issue_iid' => $job->issueIid, 'project_path' => $job->projectPath]);
            $this->writer->markFailed($job->id, $error);

            return true;
        }

        $this->logger->warning('Issue triage worker: will retry', ['attempt' => $job->attempt, 'error' => $error, 'issue_iid' => $job->issueIid, 'project_path' => $job->projectPath]);
        $this->writer->markRetry($job->id, $now->modify('+' . self::BACKOFF[$job->attempt - 1] . ' seconds'), $error);

        return true;
    }

    /**
     * Take the oldest due job: read it, lock it, and read back that the lock is this worker's
     *
     * @param string[]          $projects The enrolled projects
     * @param DateTimeImmutable $now      The current time
     *
     * @return ?IssueTriageJob The locked job with its new attempt number; null when nothing is due or another worker won the race
     */
    private function claim(array $projects, DateTimeImmutable $now): ?IssueTriageJob
    {
        $due = $this->reader->getDueJob($projects, $now, self::MAX_ATTEMPTS);
        if ($due === null) {
            return null;
        }

        $this->writer->lockJob($due->id, $due->attempt, $this->workerId, $now, self::LOCK_SECONDS);

        return $this->reader->getJobLockedBy($due->id, $this->workerId);
    }
}
