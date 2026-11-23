<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Settings;

use Kelpie\DataTransferObject\Project;
use Kelpie\DataTransferObject\ProjectSettingsPatch;
use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\Project\ProjectWriterInterface as ProjectWriter;

/**
 * An in-memory `projects` table for the settings tests: reads every row, applies a patch like the SQL would
 */
final class InMemoryProjectSettingsStore implements ProjectReader, ProjectWriter
{
    /**
     * @var array<string, Project>
     */
    private array $rows = [];

    /**
     * How many times updateSettings was called
     */
    private int $writes = 0;

    /**
     * Constructor
     *
     * @param Project ...$projects The rows
     */
    public function __construct(
        Project ...$projects,
    ) {
        foreach ($projects as $project) {
            $this->rows[$project->path] = $project;
        }
        ksort($this->rows);
    }

    /**
     * {@inheritDoc}
     */
    public function getByPath(string $path): ?Project
    {
        return $this->rows[$path] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getEnrolled(): array
    {
        $enrolled = [];
        foreach ($this->rows as $row) {
            if ($row->mode !== ProjectMode::Off) {
                $enrolled[] = $row;
            }
        }

        return $enrolled;
    }

    /**
     * {@inheritDoc}
     */
    public function getAll(): array
    {
        return array_values($this->rows);
    }

    /**
     * @return int How many times updateSettings was called
     */
    public function writeCount(): int
    {
        return $this->writes;
    }

    /**
     * {@inheritDoc}
     */
    public function updateSettings(string $path, ProjectSettingsPatch $patch): void
    {
        $this->writes++;
        $c = $patch->changes;
        $p = $this->rows[$path];

        $this->rows[$path] = new Project(
            path:                      $p->path,
            mode:                      array_key_exists('mode', $c) && $c['mode'] instanceof ProjectMode ? $c['mode'] : $p->mode,
            gateCommand:               $p->gateCommand,
            protectedPaths:            $p->protectedPaths,
            triageModel:               array_key_exists('triage_model', $c) ? (is_string($c['triage_model']) ? $c['triage_model'] : null) : $p->triageModel,
            reviewModel:               array_key_exists('review_model', $c) ? (is_string($c['review_model']) ? $c['review_model'] : null) : $p->reviewModel,
            maxIssuesPerDay:           is_int($c['max_issues_per_day'] ?? null) ? $c['max_issues_per_day'] : $p->maxIssuesPerDay,
            maxIssuesPerAuthorDay:     is_int($c['max_issues_per_author_day'] ?? null) ? $c['max_issues_per_author_day'] : $p->maxIssuesPerAuthorDay,
            maxCostUsdDay:             is_string($c['max_cost_usd_day'] ?? null) ? $c['max_cost_usd_day'] : $p->maxCostUsdDay,
            maxFixCommitsPerMr:        is_int($c['max_fix_commits_per_mr'] ?? null) ? $c['max_fix_commits_per_mr'] : $p->maxFixCommitsPerMr,
            maxCostUsdDayHard:         array_key_exists('max_cost_usd_day_hard', $c) ? (is_string($c['max_cost_usd_day_hard']) ? $c['max_cost_usd_day_hard'] : null) : $p->maxCostUsdDayHard,
            maxIssuesPerDayHard:       array_key_exists('max_issues_per_day_hard', $c) ? (is_int($c['max_issues_per_day_hard']) ? $c['max_issues_per_day_hard'] : null) : $p->maxIssuesPerDayHard,
            maxIssuesPerAuthorDayHard: array_key_exists('max_issues_per_author_day_hard', $c) ? (is_int($c['max_issues_per_author_day_hard']) ? $c['max_issues_per_author_day_hard'] : null) : $p->maxIssuesPerAuthorDayHard,
        );
    }
}
