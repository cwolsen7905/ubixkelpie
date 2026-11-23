<?php

declare(strict_types=1);

namespace Kelpie\Settings;

use Kelpie\DataTransferObject\Project;
use Psr\Log\LoggerInterface as Logger;

/**
 * The JSON shape of one project's settings
 *
 * Every cap is `{soft, hard}`; a hard of null is unlimited, a soft is always a number. Models are null for "use the default".
 * `max_fix_commits_per_mr.hard` is always null until a column holds it, so the shape stays the same when one does.
 * `gate_command` and `protected_paths` are not shown.
 *
 * @see \Kelpie\Tests\Settings\ProjectSettingsRepresentationTest PHPUnit test case
 */
final class ProjectSettingsRepresentation
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first, and the standards test enforces it; this one has nothing to log)
    ) {
    }

    /**
     * @param Project $project The project
     *
     * @return array{path: string, mode: string, settings: array<string, mixed>}
     */
    public function represent(Project $project): array
    {
        return [
            'mode'     => $project->mode->value,
            'path'     => $project->path,
            'settings' => [
                'max_cost_usd_day'          => ['soft' => $project->maxCostUsdDay, 'hard' => $project->maxCostUsdDayHard],
                'max_fix_commits_per_mr'    => ['soft' => $project->maxFixCommitsPerMr, 'hard' => null],
                'max_issues_per_author_day' => ['soft' => $project->maxIssuesPerAuthorDay, 'hard' => $project->maxIssuesPerAuthorDayHard],
                'max_issues_per_day'        => ['soft' => $project->maxIssuesPerDay, 'hard' => $project->maxIssuesPerDayHard],
                'review_model'              => $project->reviewModel,
                'triage_model'              => $project->triageModel,
            ],
        ];
    }
}
