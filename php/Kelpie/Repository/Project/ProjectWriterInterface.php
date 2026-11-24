<?php

declare(strict_types=1);

namespace Kelpie\Repository\Project;

use InvalidArgumentException;
use Kelpie\DataTransferObject\ProjectSettingsPatch;

/**
 * Writes the settings of a project Kelpie already knows
 */
interface ProjectWriterInterface
{
    /**
     * Apply a validated patch to one project's row
     *
     * Only the columns in the patch are written. Validation is the caller's job: this writes what it is given, but only
     * to the settings columns it knows, never to `path`, `gate_command` or `protected_paths`.
     *
     * @param string               $path  The project path with namespace
     * @param ProjectSettingsPatch $patch The columns to write
     *
     * @return void
     *
     * @throws InvalidArgumentException When the patch names a column that is not a setting
     */
    public function updateSettings(string $path, ProjectSettingsPatch $patch): void;
}
