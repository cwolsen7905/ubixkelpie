<?php

declare(strict_types=1);

namespace Kelpie\Service\Settings;

use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\Project\ProjectWriterInterface as ProjectWriter;
use Kelpie\Settings\Exception\ProjectNotFoundException;
use Kelpie\Settings\ProjectSettingsRepresentation;
use Kelpie\Settings\ProjectSettingsValidator;
use Psr\Log\LoggerInterface as Logger;

/**
 * Lists, reads and edits per-project settings; the one place the API's business rules run
 *
 * Controllers stay thin and call this; a CLI command could call it too. It does not create or delete rows. Every
 * change is logged with the project and the columns it touched (never the values of a secret: there are none here).
 *
 * @see \Kelpie\Tests\Service\Settings\ProjectSettingsServiceTest PHPUnit test case
 */
final class ProjectSettingsService
{
    /**
     * Constructor
     *
     * @param Logger                        $logger         The Monolog logger
     * @param ProjectReader                 $reader         Reads `projects`
     * @param ProjectWriter                 $writer         Writes `projects`
     * @param ProjectSettingsValidator      $validator      The rules
     * @param ProjectSettingsRepresentation $representation The JSON shape
     */
    public function __construct(
        private Logger $logger,
        private ProjectReader $reader,
        private ProjectWriter $writer,
        private ProjectSettingsValidator $validator,
        private ProjectSettingsRepresentation $representation,
    ) {
    }

    /**
     * Every project, including those that are off, in path order
     *
     * @return list<array{path: string, mode: string, settings: array<string, mixed>}>
     */
    public function list(): array
    {
        return array_values(array_map($this->representation->represent(...), $this->reader->getAll()));
    }

    /**
     * One project
     *
     * @param string $path The project path with namespace
     *
     * @return array{path: string, mode: string, settings: array<string, mixed>}
     *
     * @throws ProjectNotFoundException When the project has no row
     */
    public function get(string $path): array
    {
        $project = $this->reader->getByPath($path) ?? throw new ProjectNotFoundException('No project ' . $path);

        return $this->representation->represent($project);
    }

    /**
     * Apply a partial update
     *
     * A patch that changes nothing writes nothing and logs nothing. A body that breaks a rule raises
     * SettingsValidationException (see the validator) and nothing is written.
     *
     * @param string $path The project path with namespace
     * @param mixed  $body The decoded JSON body
     *
     * @return array{path: string, mode: string, settings: array<string, mixed>} The project after the update
     *
     * @throws ProjectNotFoundException When the project has no row
     */
    public function update(string $path, mixed $body): array
    {
        $project = $this->reader->getByPath($path) ?? throw new ProjectNotFoundException('No project ' . $path);
        $patch   = $this->validator->validate($body, $project);

        if ($patch->changes === []) {
            return $this->representation->represent($project);
        }

        $this->writer->updateSettings($path, $patch);
        $newMode = $patch->changes['mode'] ?? null;
        $this->logger->info('Project settings changed through the API', [
            'columns' => array_keys($patch->changes),
            'mode'    => ['from' => $project->mode->value, 'to' => $newMode instanceof ProjectMode ? $newMode->value : $project->mode->value],
            'project' => $path,
        ]);

        return $this->get($path);
    }
}
