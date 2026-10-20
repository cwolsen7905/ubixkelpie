<?php

declare(strict_types=1);

namespace Kelpie\Service\Project;

use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Psr\Log\LoggerInterface as Logger;

/**
 * Answers what Kelpie may do on a project, from its row in `projects`
 *
 * A project with no row is off. Nothing else is assumed: there is no fallback to a list in the environment.
 *
 * @see \Kelpie\Tests\Service\Project\ProjectModeServiceTest PHPUnit test case
 */
final class ProjectModeService
{
    /**
     * Constructor
     *
     * @param Logger        $logger   Logger
     * @param ProjectReader $projects The projects
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProjectReader $projects,
    ) {
    }

    /**
     * @param string $path The project path with namespace
     *
     * @return ProjectMode Off when the project has no row
     */
    public function modeOf(string $path): ProjectMode
    {
        $project = $this->projects->getByPath($path);
        if ($project === null) {
            $this->logger->debug('Project has no row, treated as off', ['project' => $path]);

            return ProjectMode::Off;
        }

        return $project->mode;
    }

    /**
     * @param string      $path   The project path with namespace
     * @param ProjectMode $needed The least mode the action needs
     *
     * @return bool
     */
    public function allows(string $path, ProjectMode $needed): bool
    {
        return $this->modeOf($path)->allows($needed);
    }

    /**
     * @return string[] The paths of every project that is not off, in path order
     */
    public function enrolledPaths(): array
    {
        return array_column($this->projects->getEnrolled(), 'path');
    }
}
