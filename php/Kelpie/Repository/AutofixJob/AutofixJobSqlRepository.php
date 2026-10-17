<?php

declare(strict_types=1);

namespace Kelpie\Repository\AutofixJob;

use DateTime;
use DateTimeImmutable;
use Kelpie\DataTransferObject\SqlRepository\AutofixJobOptions;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;

/**
 * SQL-backed autofix job repository
 *
 * @see \Kelpie\Tests\Repository\AutofixJob\AutofixJobSqlRepositoryTest PHPUnit test case
 */
final class AutofixJobSqlRepository implements AutofixJobReader, AutofixJobWriter
{
    private const string COLUMNS = 'id, project_path, mr_iid, head_sha, discussion_id, thread_key, source, after_autofix,
                status, triage_class, attempt, commit_sha, date_created';

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
    public function getJobById(int $id): ?AutofixJobRecord
    {
        return $this->query(new AutofixJobOptions(id: $id, limit: 1))[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getOpenJob(string $projectPath, int $mrIid, string $threadKey): ?AutofixJobRecord
    {
        return $this->query(new AutofixJobOptions(projectPath: $projectPath, mrIid: $mrIid, threadKey: $threadKey, openOnly: true, limit: 1))[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function countFixCommitsForMr(string $projectPath, int $mrIid): int
    {
        $sql = 'SELECT COUNT(*) FROM ubixkelpie.autofix_jobs
                WHERE project_path = :project_path AND mr_iid = :mr_iid AND commit_sha IS NOT NULL';

        return (int) $this->sqlService->getColumn($sql, ['mr_iid' => $mrIid, 'project_path' => $projectPath]);
    }

    /**
     * {@inheritDoc}
     */
    public function countAttemptsForThread(string $projectPath, int $mrIid, string $threadKey): int
    {
        $sql = "SELECT COUNT(*) FROM ubixkelpie.autofix_jobs
                WHERE project_path = :project_path AND mr_iid = :mr_iid AND thread_key = :thread_key
                AND status IN ('done', 'failed')";

        return (int) $this->sqlService->getColumn($sql, ['mr_iid' => $mrIid, 'project_path' => $projectPath, 'thread_key' => $threadKey]);
    }

    /**
     * {@inheritDoc}
     */
    public function countJobsSince(string $projectPath, DateTimeImmutable $since): int
    {
        $sql = 'SELECT COUNT(*) FROM ubixkelpie.autofix_jobs WHERE project_path = :project_path AND date_created >= :since';

        return (int) $this->sqlService->getColumn($sql, ['project_path' => $projectPath, 'since' => $since->format('Y-m-d H:i:s')]);
    }

    /**
     * {@inheritDoc}
     */
    public function createJob(AutofixJobRecord $job): void
    {
        $sql = "INSERT INTO ubixkelpie.autofix_jobs (project_path, mr_iid, head_sha, discussion_id, thread_key, source, after_autofix, status)
                VALUES (:project_path, :mr_iid, :head_sha, :discussion_id, :thread_key, :source, :after_autofix, 'pending')";

        $this->sqlService->query($sql, [
            'after_autofix' => $job->getAfterAutofix() === true ? 1 : 0,
            'discussion_id' => $job->getDiscussionId(),
            'head_sha'      => $job->getHeadSha(),
            'mr_iid'        => $job->getMrIid(),
            'project_path'  => $job->getProjectPath(),
            'source'        => $job->getSource(),
            'thread_key'    => $job->getThreadKey() ?? '',
        ]);

        $job->setId((int) $this->sqlService->lastInsertId());
    }

    /**
     * {@inheritDoc}
     */
    public function supersedeJob(int $id): void
    {
        $sql = "UPDATE ubixkelpie.autofix_jobs SET status = 'superseded' WHERE id = :id AND status = 'pending'";

        $this->sqlService->query($sql, ['id' => $id]);
    }

    /**
     * {@inheritDoc}
     */
    public function markRunning(int $id): void
    {
        $sql = "UPDATE ubixkelpie.autofix_jobs SET status = 'running', attempt = attempt + 1 WHERE id = :id AND status = 'pending'";

        $this->sqlService->query($sql, ['id' => $id]);
    }

    /**
     * {@inheritDoc}
     */
    public function markFinished(int $id, string $status, ?string $triageClass = null, ?string $commitSha = null): void
    {
        $sql = "UPDATE ubixkelpie.autofix_jobs SET status = :status, triage_class = :triage_class, commit_sha = :commit_sha
                WHERE id = :id AND status = 'running'";

        $this->sqlService->query($sql, ['commit_sha' => $commitSha, 'id' => $id, 'status' => $status, 'triage_class' => $triageClass]);
    }

    /**
     * Query autofix jobs
     *
     * @param AutofixJobOptions $options DTO of options to generate the query
     *
     * @return array<int, AutofixJobRecord> The matching jobs, newest first
     */
    private function query(AutofixJobOptions $options): array
    {
        $sql        = 'SELECT ' . self::COLUMNS . ' FROM ubixkelpie.autofix_jobs';
        $where      = [];
        $parameters = [];

        foreach (['id' => 'id', 'project_path' => 'projectPath', 'mr_iid' => 'mrIid', 'thread_key' => 'threadKey', 'status' => 'status'] as $column => $property) {
            if ($options->{$property} !== null) {
                $where[]             = $column . ' = :' . $column;
                $parameters[$column] = $options->{$property};
            }
        }

        if ($options->openOnly) {
            $where[] = 'open_marker = 1';
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY id DESC';

        if ($options->limit !== null) {
            $sql .= ' LIMIT ' . $options->limit;
        }

        $jobs = [];
        foreach ($this->sqlService->getRows($sql, $parameters) as $row) {
            $jobs[] = $this->hydrateJob($row);
        }

        return $jobs;
    }

    /**
     * Hydrate an AutofixJob model from a database row
     *
     * @param array<string, bool|int|float|string|null> $row The database result row
     *
     * @return AutofixJobRecord The hydrated job
     */
    private function hydrateJob(array $row): AutofixJobRecord
    {
        return new AutofixJobRecord(
            id:           (int) $row['id'],
            projectPath:  (string) $row['project_path'],
            mrIid:        (int) $row['mr_iid'],
            headSha:      (string) $row['head_sha'],
            discussionId: is_string($row['discussion_id']) ? $row['discussion_id'] : null,
            threadKey:    (string) $row['thread_key'],
            source:       (string) $row['source'],
            afterAutofix: (bool) $row['after_autofix'],
            status:       (string) $row['status'],
            triageClass:  is_string($row['triage_class']) ? $row['triage_class'] : null,
            attempt:      (int) $row['attempt'],
            commitSha:    is_string($row['commit_sha']) ? $row['commit_sha'] : null,
            dateCreated:  is_string($row['date_created']) ? new DateTime($row['date_created']) : null,
        );
    }
}
