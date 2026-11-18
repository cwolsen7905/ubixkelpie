<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\Enum\IssueFixOutcome;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Takes at most one due issue fix job and settles it
 *
 * The kill switch and the project list are checked on every step: with autofix off, or for a project not on the list,
 * nothing is claimed and the jobs stay pending. Only `Retry` (nothing was changed anywhere) is tried again, once, after a
 * delay; every other outcome settles the job, because a fix is one attempt and a person asks for another.
 *
 * @see \Kelpie\Tests\Service\Autofix\IssueFixWorkerServiceTest PHPUnit test case
 */
final class IssueFixWorkerService
{
    public const int LOCK_SECONDS = 2400;

    public const int MAX_ATTEMPTS = 2;

    private const int RETRY_SECONDS = 300;

    /**
     * Constructor
     *
     * @param Logger               $logger   Logger
     * @param IssueTriageJobReader $reader   Reads the due jobs
     * @param IssueTriageJobWriter $writer   Locks and settles jobs
     * @param IssueFixService      $fixer    The issue fix service
     * @param string               $workerId Names this worker in the job lock
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly IssueTriageJobReader $reader,
        private readonly IssueTriageJobWriter $writer,
        private readonly IssueFixService $fixer,
        private readonly string $workerId = 'worker',
    ) {
    }

    /**
     * Run one step
     *
     * @param bool              $enabled  Whether autofix is on (KELPIE_AUTOFIX_ENABLED)
     * @param string[]          $projects The enrolled projects
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
            $this->logger->error('Issue fix worker: claim failed', ['error' => $e->getMessage()]);

            return false;
        }
        if ($job === null) {
            return false;
        }

        try {
            $outcome = $this->fixer->fix($job->projectPath, $job->issueIid);
        } catch (Throwable $e) {
            $this->logger->error('Issue fix worker: the fix threw', ['error' => $e->getMessage(), 'job_id' => $job->id]);
            $outcome = IssueFixOutcome::Retry;
        }

        if ($outcome !== IssueFixOutcome::Retry) {
            $this->writer->markDone($job->id, $outcome->value);

            return true;
        }

        if ($job->attempt >= self::MAX_ATTEMPTS) {
            $this->logger->error('Issue fix worker: giving up', ['issue_iid' => $job->issueIid, 'project_path' => $job->projectPath]);
            $this->writer->markFailed($job->id, 'nothing could be done after ' . self::MAX_ATTEMPTS . ' tries');

            return true;
        }

        $this->logger->warning('Issue fix worker: will retry', ['attempt' => $job->attempt, 'issue_iid' => $job->issueIid, 'project_path' => $job->projectPath]);
        $this->writer->markRetry($job->id, $now->modify('+' . self::RETRY_SECONDS . ' seconds'), 'nothing could be done this time');

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
        $due = $this->reader->getDueFixJob($projects, $now, self::MAX_ATTEMPTS);
        if ($due === null) {
            return null;
        }

        $this->writer->lockJob($due->id, $due->attempt, $this->workerId, $now, self::LOCK_SECONDS);

        return $this->reader->getFixJobLockedBy($due->id, $this->workerId);
    }
}
