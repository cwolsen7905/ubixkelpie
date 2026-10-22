<?php

declare(strict_types=1);

namespace Kelpie\Tests\Repository\Project;

use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Service\Project\ProjectModeService;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;

/**
 * An in-memory `projects` table for tests: the rows given to the constructor, nothing else
 *
 * A path that is not given has no row, which the service reads as off. Rows come back in the order given.
 */
final class InMemoryProjects implements ProjectReader
{
    /**
     * @var array<string, Project>
     */
    private array $rows = [];

    /**
     * Constructor
     *
     * @param array<string, ProjectMode> $modes Project path => mode
     */
    public function __construct(
        array $modes = [],
    ) {
        foreach ($modes as $path => $mode) {
            $this->rows[$path] = new Project($path, $mode);
        }
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
     * The service over a table with these rows
     *
     * @param array<string, ProjectMode> $modes  Project path => mode
     * @param ?Logger                    $logger The logger, a null logger by default
     *
     * @return ProjectModeService
     */
    public static function service(array $modes = [], ?Logger $logger = null): ProjectModeService
    {
        return new ProjectModeService($logger ?? new NullLogger(), new self($modes));
    }
}
