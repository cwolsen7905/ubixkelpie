<?php

declare(strict_types=1);

namespace Kelpie\Repository\Project;

use Kelpie\DataTransferObject\Project;

/**
 * Reads the projects Kelpie is enrolled in
 */
interface ProjectReaderInterface
{
    /**
     * One project's row
     *
     * @param string $path The project path with namespace
     *
     * @return ?Project Null when the project has no row
     */
    public function getByPath(string $path): ?Project;

    /**
     * Every project whose mode is not off
     *
     * @return Project[] In path order
     */
    public function getEnrolled(): array;
}
