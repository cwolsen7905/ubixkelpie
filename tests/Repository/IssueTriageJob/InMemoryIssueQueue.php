<?php

declare(strict_types=1);

namespace Kelpie\Tests\Repository\IssueTriageJob;

use DateTimeImmutable;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use RuntimeException;

/**
 * An in-memory issue triage queue that records what was done to it
 *
 * `getDueJob()` hands out the jobs given to the constructor, one per call, with the attempts they had made; a lock
 * succeeds (attempt + 1) unless the double is told another worker won. Writes are recorded as strings.
 */
final class InMemoryIssueQueue implements IssueTriageJobReader, IssueTriageJobWriter
{
    /**
     * "path#iid" of every enqueue
     *
     * @var string[]
     */
    private array $enqueued = [];

    /**
     * What lockJob, markDone, markRetry and markFailed were called with, as strings
     *
     * @var string[]
     */
    private array $settled = [];

    /**
     * The project list of every getDueJob, as a string
     *
     * @var string[]
     */
    private array $claims = [];

    /**
     * The job handed out last and not yet locked, by id
     *
     * @var array<int, IssueTriageJob>
     */
    private array $offered = [];

    /**
     * Whether enqueue throws, as a database outage would
     */
    private bool $enqueueThrows = false;

    /**
     * Whether getDueJob throws, as a missing column would
     */
    private bool $claimThrows = false;

    /**
     * Whether another worker always wins the lock
     */
    private bool $lockLost = false;

    /**
     * Constructor
     *
     * @param IssueTriageJob[] $jobs The due jobs, with the attempts they had made
     */
    public function __construct(
        private array $jobs = [],
    ) {
    }

    /**
     * Make enqueue throw
     *
     * @return self
     */
    public function enqueueThrows(): self
    {
        $this->enqueueThrows = true;

        return $this;
    }

    /**
     * Make getDueJob throw
     *
     * @return self
     */
    public function claimThrows(): self
    {
        $this->claimThrows = true;

        return $this;
    }

    /**
     * Make another worker win every lock
     *
     * @return self
     */
    public function loseTheLock(): self
    {
        $this->lockLost = true;

        return $this;
    }

    /**
     * @return string[]
     */
    public function enqueued(): array
    {
        return $this->enqueued;
    }

    /**
     * @return string[]
     */
    public function settled(): array
    {
        return $this->settled;
    }

    /**
     * @return string[]
     */
    public function claims(): array
    {
        return $this->claims;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException When told to
     */
    public function getDueJob(array $projects, DateTimeImmutable $now, int $maxAttempts): ?IssueTriageJob // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- the double ignores time and attempts
    {
        $this->claims[] = implode(',', $projects);
        if ($this->claimThrows) {
            throw new RuntimeException('column issue_iid is missing');
        }

        $job = array_shift($this->jobs);
        if ($job !== null) {
            $this->offered[$job->id] = $job;
        }

        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function getJobLockedBy(int $id, string $workerId): ?IssueTriageJob // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- the double has one worker
    {
        $job = $this->offered[$id] ?? null;

        return $job === null || $this->lockLost ? null : new IssueTriageJob($job->id, $job->projectPath, $job->issueIid, $job->attempt + 1);
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException When told to
     */
    public function enqueue(string $projectPath, int $issueIid): void
    {
        if ($this->enqueueThrows) {
            throw new RuntimeException('database down');
        }

        $key = $projectPath . '#' . $issueIid;
        if (!in_array($key, $this->enqueued, true)) {
            $this->enqueued[] = $key;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function lockJob(int $id, int $attempt, string $workerId, DateTimeImmutable $now, int $lockSeconds): void // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- the double ignores the lease
    {
        $this->settled[] = 'lock ' . $id . ' ' . $attempt;
    }

    /**
     * {@inheritDoc}
     */
    public function markDone(int $id, ?string $triageClass): void
    {
        $this->settled[] = 'done ' . $id . ' ' . ($triageClass ?? '-');
    }

    /**
     * {@inheritDoc}
     */
    public function markRetry(int $id, DateTimeImmutable $nextAttemptAt, string $error): void
    {
        $this->settled[] = 'retry ' . $id . ' ' . $nextAttemptAt->format('H:i:s') . ' ' . $error;
    }

    /**
     * {@inheritDoc}
     */
    public function markFailed(int $id, string $error): void
    {
        $this->settled[] = 'failed ' . $id . ' ' . $error;
    }
}
