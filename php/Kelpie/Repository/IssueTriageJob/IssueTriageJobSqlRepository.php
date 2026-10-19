<?php

declare(strict_types=1);

namespace Kelpie\Repository\IssueTriageJob;

use DateTimeImmutable;
use DateTimeZone;
use Kelpie\DataTransferObject\IssueTriageJob;
use Kelpie\DataTransferObject\SqlRepository\IssueTriageJobOptions;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobReaderInterface as IssueTriageJobReader;
use Kelpie\Repository\IssueTriageJob\IssueTriageJobWriterInterface as IssueTriageJobWriter;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;

/**
 * SQL-backed issue triage queue on `autofix_jobs` (source `issue_triage`)
 *
 * The thread key is `issue-<iid>` so the table's open-job unique key keeps one open job per issue
 * and a second issue in the same project does not collide with it. Times written here are UTC
 * and compared only with times written here.
 *
 * @see \Kelpie\Tests\Repository\IssueTriageJob\IssueTriageJobSqlRepositoryTest PHPUnit test case
 */
final class IssueTriageJobSqlRepository implements IssueTriageJobReader, IssueTriageJobWriter
{
    public const string SOURCE = 'issue_triage';

    /**
     * Constructor
     *
     * @param Logger     $logger     The Monolog logger
     * @param SqlService $sqlService The SQL service
     */
    public function __construct(
        private Logger $logger, // @phpstan-ignore property.onlyWritten (required first dependency of every repository)
        private SqlService $sqlService,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getDueJob(array $projects, DateTimeImmutable $now, int $maxAttempts): ?IssueTriageJob
    {
        if ($projects === []) {
            return null;
        }

        return $this->query(new IssueTriageJobOptions(projects: $projects, dueAt: $now, maxAttempts: $maxAttempts));
    }

    /**
     * {@inheritDoc}
     */
    public function getJobLockedBy(int $id, string $workerId): ?IssueTriageJob
    {
        return $this->query(new IssueTriageJobOptions(id: $id, workerId: $workerId));
    }

    /**
     * {@inheritDoc}
     */
    public function getJobForIssue(string $projectPath, int $issueIid): ?IssueTriageJob
    {
        return $this->query(new IssueTriageJobOptions(projectPath: $projectPath, issueIid: $issueIid));
    }

    /**
     * {@inheritDoc}
     */
    public function enqueue(string $projectPath, int $issueIid): void
    {
        $sql = "INSERT IGNORE INTO ubixkelpie.autofix_jobs (project_path, mr_iid, issue_iid, head_sha, thread_key, source, status)
                VALUES (:project_path, 0, :issue_iid, '', :thread_key, :source, 'pending')";

        $this->sqlService->query($sql, [
            'issue_iid'    => $issueIid,
            'project_path' => $projectPath,
            'source'       => self::SOURCE,
            'thread_key'   => 'issue-' . $issueIid,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function lockJob(int $id, int $attempt, string $workerId, DateTimeImmutable $now, int $lockSeconds): void
    {
        $sql = "UPDATE ubixkelpie.autofix_jobs
                SET status = 'running', attempt = attempt + 1, locked_by = :locked_by, locked_until = :locked_until
                WHERE id = :id AND attempt = :attempt
                AND (status = 'pending' OR (status = 'running' AND locked_until < :lock_now))";

        $this->sqlService->query($sql, [
            'attempt'      => $attempt,
            'id'           => $id,
            'locked_by'    => $workerId,
            'locked_until' => $this->stamp($now->modify('+' . $lockSeconds . ' seconds')),
            'lock_now'     => $this->stamp($now),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function markDone(int $id, ?string $triageClass): void
    {
        $sql = "UPDATE ubixkelpie.autofix_jobs SET status = 'done', triage_class = :triage_class, locked_by = NULL, locked_until = NULL, last_error = NULL
                WHERE id = :id AND status = 'running'";

        $this->sqlService->query($sql, ['id' => $id, 'triage_class' => $triageClass]);
    }

    /**
     * {@inheritDoc}
     */
    public function markRetry(int $id, DateTimeImmutable $nextAttemptAt, string $error): void
    {
        $sql = "UPDATE ubixkelpie.autofix_jobs SET status = 'pending', next_attempt_at = :next_attempt_at, locked_by = NULL, locked_until = NULL, last_error = :last_error
                WHERE id = :id AND status = 'running'";

        $this->sqlService->query($sql, ['id' => $id, 'last_error' => mb_substr($error, 0, 255), 'next_attempt_at' => $this->stamp($nextAttemptAt)]);
    }

    /**
     * {@inheritDoc}
     */
    public function markFailed(int $id, string $error): void
    {
        $sql = "UPDATE ubixkelpie.autofix_jobs SET status = 'failed', locked_by = NULL, locked_until = NULL, last_error = :last_error
                WHERE id = :id AND status = 'running'";

        $this->sqlService->query($sql, ['id' => $id, 'last_error' => mb_substr($error, 0, 255)]);
    }

    /**
     * Query issue triage jobs
     *
     * @param IssueTriageJobOptions $options DTO of options to generate the query
     *
     * @return ?IssueTriageJob The first matching job, or null
     */
    private function query(IssueTriageJobOptions $options): ?IssueTriageJob
    {
        $sql        = 'SELECT id, project_path, issue_iid, attempt FROM ubixkelpie.autofix_jobs WHERE source = :source';
        $parameters = ['source' => self::SOURCE];

        if ($options->issueIid !== null) {
            $sql                       .= ' AND project_path = :project_path AND issue_iid = :issue_iid';
            $parameters['project_path'] = $options->projectPath;
            $parameters['issue_iid']    = $options->issueIid;
        } elseif ($options->workerId !== null) {
            $sql                    .= " AND id = :id AND status = 'running' AND locked_by = :locked_by";
            $parameters['id']        = $options->id;
            $parameters['locked_by'] = $options->workerId;
        } else {
            $stamp        = $this->stamp($options->dueAt ?? new DateTimeImmutable());
            $placeholders = [];
            foreach (array_values($options->projects) as $i => $path) {
                $placeholders[]              = ':project_' . $i;
                $parameters['project_' . $i] = $path;
            }

            $sql                       .= ' AND attempt < :max_attempts AND project_path IN (' . implode(', ', $placeholders) . ")
                AND (status = 'pending' OR (status = 'running' AND locked_until < :lock_now))
                AND (next_attempt_at IS NULL OR next_attempt_at <= :due_now)
                ORDER BY id";
            $parameters['due_now']      = $stamp;
            $parameters['lock_now']     = $stamp;
            $parameters['max_attempts'] = $options->maxAttempts;
        }

        $row = $this->sqlService->getRow($sql . ' LIMIT 1', $parameters);

        return $row === false ? null : new IssueTriageJob((int) $row['id'], (string) $row['project_path'], (int) $row['issue_iid'], (int) $row['attempt']);
    }

    /**
     * @param DateTimeImmutable $time The time
     *
     * @return string It in UTC as a DATETIME
     */
    private function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
