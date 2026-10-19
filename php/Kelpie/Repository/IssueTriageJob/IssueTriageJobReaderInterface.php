<?php

declare(strict_types=1);

namespace Kelpie\Repository\IssueTriageJob;

use DateTimeImmutable;
use Kelpie\DataTransferObject\IssueTriageJob;

/**
 * Reads the issue triage queue
 */
interface IssueTriageJobReaderInterface
{
    /**
     * The oldest job that is due, in one of the given projects
     *
     * A job is due when it is pending, or running with an expired lock (a crashed worker), has
     * attempts left, and its retry time has passed.
     *
     * @param string[]          $projects    The project paths that may be taken
     * @param DateTimeImmutable $now         The current time
     * @param int               $maxAttempts Jobs with this many attempts are not due
     *
     * @return ?IssueTriageJob The job with the attempt it has made so far; null when nothing is due
     */
    public function getDueJob(array $projects, DateTimeImmutable $now, int $maxAttempts): ?IssueTriageJob;

    /**
     * The running job a worker holds the lock of
     *
     * @param int    $id       The job id
     * @param string $workerId The worker that tried to lock it
     *
     * @return ?IssueTriageJob The job with the attempt this lock made; null when another worker holds it
     */
    public function getJobLockedBy(int $id, string $workerId): ?IssueTriageJob;

    /**
     * The job of one issue, in whatever state it is in
     *
     * @param string $projectPath The project path with namespace
     * @param int    $issueIid    The issue number in the project
     *
     * @return ?IssueTriageJob Null when the issue was never queued
     */
    public function getJobForIssue(string $projectPath, int $issueIid): ?IssueTriageJob;
}
