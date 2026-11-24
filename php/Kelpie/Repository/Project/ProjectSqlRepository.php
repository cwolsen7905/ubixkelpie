<?php

declare(strict_types=1);

namespace Kelpie\Repository\Project;

use InvalidArgumentException;
use Kelpie\DataTransferObject\Project;
use Kelpie\DataTransferObject\ProjectSettingsPatch;
use Kelpie\DataTransferObject\SqlRepository\ProjectOptions;
use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\Project\ProjectWriterInterface as ProjectWriter;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;

/**
 * SQL-backed projects, on `ubixkelpie.projects`
 *
 * A mode the database holds that Kelpie does not know reads as off: an unreadable row never widens what Kelpie may do.
 *
 * @see \Kelpie\Tests\Repository\Project\ProjectSqlRepositoryTest PHPUnit test case
 */
final class ProjectSqlRepository implements ProjectReader, ProjectWriter
{
    private const string COLUMNS = 'path, mode, gate_command, protected_paths, triage_model, review_model,
        max_issues_per_day, max_issues_per_author_day, max_cost_usd_day, max_fix_commits_per_mr,
        max_cost_usd_day_hard, max_issues_per_day_hard, max_issues_per_author_day_hard';

    /**
     * The columns a settings patch may write; `path`, `gate_command` and `protected_paths` are not among them
     */
    private const array SETTING_COLUMNS = [
        'mode', 'triage_model', 'review_model',
        'max_issues_per_day', 'max_issues_per_day_hard',
        'max_issues_per_author_day', 'max_issues_per_author_day_hard',
        'max_cost_usd_day', 'max_cost_usd_day_hard',
        'max_fix_commits_per_mr',
    ];

    /**
     * Constructor
     *
     * @param Logger      $logger      The Monolog logger
     * @param SqlService  $sqlService  The SQL service
     * @param JsonService $jsonService The JSON service
     */
    public function __construct(
        private Logger $logger,
        private SqlService $sqlService,
        private JsonService $jsonService,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getByPath(string $path): ?Project
    {
        $projects = $this->query(new ProjectOptions(path: $path));

        return $projects[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getEnrolled(): array
    {
        return $this->query(new ProjectOptions());
    }

    /**
     * {@inheritDoc}
     */
    public function getAll(): array
    {
        $projects = [];
        foreach ($this->sqlService->getRows('SELECT ' . self::COLUMNS . ' FROM ubixkelpie.projects ORDER BY path') as $row) {
            $projects[] = $this->hydrate($row);
        }

        return $projects;
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException When the patch names a column that is not a setting
     */
    public function updateSettings(string $path, ProjectSettingsPatch $patch): void
    {
        $assignments = [];
        $parameters  = ['path' => $path];

        foreach ($patch->changes as $column => $value) {
            if (!in_array($column, self::SETTING_COLUMNS, true)) {
                throw new InvalidArgumentException('Not a project setting: ' . $column);
            }
            // The column name is from the list above, never from the caller; the value is always bound.
            $assignments[]       = $column . ' = :' . $column;
            $parameters[$column] = $value instanceof ProjectMode ? $value->value : $value;
        }

        if ($assignments === []) {
            return;
        }

        $this->sqlService->query('UPDATE ubixkelpie.projects SET ' . implode(', ', $assignments) . ' WHERE path = :path', $parameters);
    }

    /**
     * Query projects
     *
     * @param ProjectOptions $options DTO of options to generate the query
     *
     * @return Project[]
     */
    private function query(ProjectOptions $options): array
    {
        $sql        = 'SELECT ' . self::COLUMNS . ' FROM ubixkelpie.projects';
        $parameters = [];

        if ($options->path !== null) {
            $sql               .= ' WHERE path = :path';
            $parameters['path'] = $options->path;
        } else {
            $sql .= " WHERE mode <> 'off' ORDER BY path";
        }

        $projects = [];
        foreach ($this->sqlService->getRows($sql, $parameters) as $row) {
            $project = $this->hydrate($row);
            // The SQL leaves out 'off'; a mode Kelpie does not know reads as off here, so it is not enrolled either.
            if ($options->path === null && $project->mode === ProjectMode::Off) {
                continue;
            }

            $projects[] = $project;
        }

        return $projects;
    }

    /**
     * @param array<string, bool|int|float|string|null> $row One row of the query
     *
     * @return Project
     */
    private function hydrate(array $row): Project
    {
        $path = (string) $row['path'];
        $mode = ProjectMode::tryFrom((string) $row['mode']);
        if ($mode === null) {
            $this->logger->warning('Unknown project mode, treated as off', ['project' => $path, 'mode' => $row['mode']]);
            $mode = ProjectMode::Off;
        }

        return new Project(
            path:                      $path,
            mode:                      $mode,
            gateCommand:               $this->nullableString($row['gate_command']),
            protectedPaths:            $this->globs($row['protected_paths'], $path),
            triageModel:               $this->nullableString($row['triage_model']),
            reviewModel:               $this->nullableString($row['review_model']),
            maxIssuesPerDay:           (int) $row['max_issues_per_day'],
            maxIssuesPerAuthorDay:     (int) $row['max_issues_per_author_day'],
            maxCostUsdDay:             (string) $row['max_cost_usd_day'],
            maxFixCommitsPerMr:        (int) $row['max_fix_commits_per_mr'],
            maxCostUsdDayHard:         $this->nullableString($row['max_cost_usd_day_hard']),
            maxIssuesPerDayHard:       $row['max_issues_per_day_hard'] === null ? null : (int) $row['max_issues_per_day_hard'],
            maxIssuesPerAuthorDayHard: $row['max_issues_per_author_day_hard'] === null ? null : (int) $row['max_issues_per_author_day_hard'],
        );
    }

    /**
     * @param bool|int|float|string|null $value A column that may be NULL or empty
     *
     * @return ?string
     */
    private function nullableString(bool|int|float|string|null $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @param bool|int|float|string|null $json The JSON column: a list of globs, or NULL
     * @param string                     $path The project, for the log
     *
     * @return string[] Unreadable JSON gives none and a warning
     */
    private function globs(bool|int|float|string|null $json, string $path): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = $this->jsonService->decode((string) $json);
        } catch (DtoException $e) {
            $this->logger->warning('Protected paths are not valid JSON, ignored', ['project' => $path, 'error' => $e->getMessage()]);

            return [];
        }

        if (!array_is_list($decoded)) {
            $this->logger->warning('Protected paths are not a JSON list of strings, ignored', ['project' => $path]);

            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
