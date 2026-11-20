<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\ProjectInfo;
use Kelpie\DataTransferObject\TriageLlmChoice;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Psr\Log\LoggerInterface as Logger;

/**
 * Picks the triage model for a project, and keeps a model that trains on its prompts off private code
 *
 * The guard (`refusalFor`) also covers the edit-proposal model (`ProposeEditsService`).
 *
 * The model is the project's `triage_model` (its `projects` row), else `KELPIE_TRIAGE_MODEL`, else the caller's
 * default. A model id ending in `-contributor` is an OpenRouter tier that trains on the prompts it receives, the
 * code under review included, so it may only see a project GitLab reports as `public`. When the visibility cannot
 * be read (no project given, a failed call), the project is not known to be public and the model is refused:
 * the thread goes to a person instead of the code going to a training tier.
 *
 * @see \Kelpie\Tests\Service\Triage\TriageLlmServiceTest PHPUnit test case
 */
final class TriageLlmService
{
    private const string TRAINING_TIER_SUFFIX = '-contributor';

    /**
     * Constructor
     *
     * @param Logger              $logger   Logger
     * @param ProjectReader       $projects The `projects` table, for the per-project override
     * @param GitlabClientFactory $gitlab   GitLab clients, for the project's path and visibility
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProjectReader $projects,
        private readonly GitlabClientFactory $gitlab,
    ) {
    }

    /**
     * Choose the model for one triage call
     *
     * @param ?string $project      The GitLab project ID or path with namespace; null when not known
     * @param string  $defaultModel The caller's default when nothing is configured
     *
     * @return TriageLlmChoice
     */
    public function choose(?string $project, string $defaultModel): TriageLlmChoice
    {
        $info     = $project === null || $project === '' ? null : $this->gitlab->projectInfo()->get($project);
        $path     = $info !== null ? $info->path : ($project !== null && str_contains($project, '/') ? $project : null);
        $override = $path === null ? null : $this->projects->getByPath($path)?->triageModel;
        $model    = $override ?? '';
        if ($model === '') {
            $model = (string) getenv('KELPIE_TRIAGE_MODEL') ?: $defaultModel;
        }

        return new TriageLlmChoice($model, $this->refusalFor($model, $project, $info));
    }

    /**
     * Why a model must not see a project's code, or empty when it may
     *
     * Used for every call that sends code to a model: triage, and the edit proposals that follow it.
     *
     * @param string       $model   The model id
     * @param ?string      $project The GitLab project ID or path with namespace; null when not known
     * @param ?ProjectInfo $info    What GitLab already said about the project, to save a second read
     *
     * @return string
     */
    public function refusalFor(string $model, ?string $project, ?ProjectInfo $info = null): string
    {
        if (!$this->trainsOnPrompts($model)) {
            return '';
        }

        $info ??= $project === null || $project === '' ? null : $this->gitlab->projectInfo()->get($project);
        if ($info?->visibility === 'public') {
            return '';
        }

        $this->logger->warning('A training-tier model was refused for a project not known to be public', ['model' => $model, 'project' => $project, 'visibility' => $info?->visibility]);

        return sprintf('Refused: %s trains on the prompts it receives, and this project is not known to be public, so its code may not be sent to it.', $model);
    }

    /**
     * Whether a model id names a tier that trains on its prompts
     *
     * @param string $model The model id
     *
     * @return bool
     */
    public function trainsOnPrompts(string $model): bool
    {
        return str_ends_with(strtolower(trim($model)), self::TRAINING_TIER_SUFFIX);
    }
}
