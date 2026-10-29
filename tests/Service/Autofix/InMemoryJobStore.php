<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;

/**
 * An in-memory job store for tests: one job, with the real state transitions of the SQL repository
 */
final class InMemoryJobStore implements AutofixJobReader, AutofixJobWriter
{
    /**
     * Status of the job: pending, running, superseded, done or failed
     */
    private string $status;

    private ?string $triageClass = null;

    private ?string $commitSha = null;

    private int $attempt = 0;

    /**
     * Every call as "method:arg,arg", so a test can see what was asked
     *
     * @var list<string>
     */
    private array $log = [];

    /**
     * Constructor
     *
     * @param int    $attemptsOnThread Finished attempts already on the thread
     * @param int    $commitsOnMr      Fix commits already on the MR
     * @param string $status           Where the job starts: pending, or running when a worker holds its lease
     */
    public function __construct(
        private readonly int $attemptsOnThread = 0,
        private readonly int $commitsOnMr = 0,
        string $status = 'pending',
    ) {
        $this->status = $status;
    }

    /**
     * The recorded calls
     *
     * @return list<string>
     */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * @return string
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return ?string
     */
    public function triageClass(): ?string
    {
        return $this->triageClass;
    }

    /**
     * @return ?string
     */
    public function commitSha(): ?string
    {
        return $this->commitSha;
    }

    /**
     * @return int
     */
    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * {@inheritDoc}
     */
    public function getJobById(int $id): ?AutofixJobRecord
    {
        $this->log[] = __FUNCTION__ . ':' . $id;

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getOpenJob(string $projectPath, int $mrIid, string $threadKey): ?AutofixJobRecord
    {
        $this->log[] = __FUNCTION__ . ':' . $projectPath . ',' . $mrIid . ',' . $threadKey;

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function countFixCommitsForMr(string $projectPath, int $mrIid): int
    {
        $this->log[] = __FUNCTION__ . ':' . $projectPath . ',' . $mrIid;

        return $this->commitsOnMr;
    }

    /**
     * {@inheritDoc}
     */
    public function countAttemptsForThread(string $projectPath, int $mrIid, string $threadKey): int
    {
        $this->log[] = __FUNCTION__ . ':' . $projectPath . ',' . $mrIid . ',' . $threadKey;

        return $this->attemptsOnThread;
    }

    /**
     * {@inheritDoc}
     */
    public function countJobsSince(string $projectPath, DateTimeImmutable $since): int
    {
        $this->log[] = __FUNCTION__ . ':' . $projectPath . ',' . $since->format('c');

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function createJob(AutofixJobRecord $job): void
    {
        $job->setId(1);
    }

    /**
     * {@inheritDoc}
     */
    public function supersedeJob(int $id): void
    {
        $this->log[]  = __FUNCTION__ . ':' . $id;
        $this->status = in_array($this->status, ['pending', 'running'], true) ? 'superseded' : $this->status;
    }

    /**
     * {@inheritDoc}
     */
    public function markRunning(int $id): void
    {
        $this->log[] = __FUNCTION__ . ':' . $id;
        if ($this->status === 'pending') {
            $this->status = 'running';
            $this->attempt++;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function markFinished(int $id, string $status, ?string $triageClass = null, ?string $commitSha = null): void
    {
        $this->log[] = __FUNCTION__ . ':' . $id . ',' . $status;
        if ($this->status === 'running') {
            $this->status      = $status;
            $this->triageClass = $triageClass;
            $this->commitSha   = $commitSha;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getDueJob(array $projects, DateTimeImmutable $now, int $maxAttempts): ?AutofixJobRecord
    {
        $this->log[] = __FUNCTION__ . ':' . implode(',', $projects) . ',' . $now->format('c') . ',' . $maxAttempts;

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getJobLockedBy(int $id, string $workerId): ?AutofixJobRecord
    {
        $this->log[] = __FUNCTION__ . ':' . $id . ',' . $workerId;

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function lockJob(int $id, int $attempt, string $workerId, DateTimeImmutable $now, int $lockSeconds): void
    {
        $this->log[] = __FUNCTION__ . ':' . $id . ',' . $workerId . ',' . $attempt . ',' . $now->format('c') . ',' . $lockSeconds;
    }

    /**
     * {@inheritDoc}
     */
    public function markDone(int $id): void
    {
        $this->log[] = __FUNCTION__ . ':' . $id;
    }

    /**
     * {@inheritDoc}
     */
    public function markRetry(int $id, DateTimeImmutable $nextAttemptAt, string $error): void
    {
        $this->log[] = __FUNCTION__ . ':' . $id . ',' . $nextAttemptAt->format('c') . ',' . $error;
    }

    /**
     * {@inheritDoc}
     */
    public function markDeferred(int $id, DateTimeImmutable $until, string $why): void
    {
        $this->log[] = __FUNCTION__ . ':' . $id . ',' . $until->format('c') . ',' . $why;
    }

    /**
     * {@inheritDoc}
     */
    public function markFailed(int $id, string $error): void
    {
        $this->log[] = __FUNCTION__ . ':' . $id . ',' . $error;
    }
}
