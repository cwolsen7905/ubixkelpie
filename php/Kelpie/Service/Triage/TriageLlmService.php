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
 * A project is open source when GitLab detects an OSI open-source license in its repository (its `LICENSE` file).
 * GitLab visibility is not used: the canonical repos are private on GitLab and published by mirroring.
 *
 * Triage model: the project's `triage_model` (its `projects` row); else, for an open-source project,
 * `KELPIE_TRIAGE_MODEL_PUBLIC` (default `meta/muse-spark-1.3-contributor`, cheap); else `KELPIE_TRIAGE_MODEL`, else
 * the caller's default. When the model is the open-source one (not a project override), the choice carries the regular
 * model as its fallback: a training tier the OpenRouter account's data policy excludes answers 404 on every call, and
 * the thread should still be triaged rather than all of them going to a person. Propose model: for an open-source project `KELPIE_PROPOSE_MODEL_PUBLIC` when set, else the
 * caller's model. A model id ending in `-contributor` is an OpenRouter tier that trains on the prompts it receives,
 * the code included, so it may only see an open-source project. When the license cannot be read (no project
 * given, a failed call), the project is not known to be open source and such a model is refused: the thread goes
 * to a person instead of the code going to a training tier.
 *
 * @see \Kelpie\Tests\Service\Triage\TriageLlmServiceTest PHPUnit test case
 */
final class TriageLlmService
{
    private const string TRAINING_TIER_SUFFIX = '-contributor';

    private const string DEFAULT_PUBLIC_TRIAGE_MODEL = 'meta/muse-spark-1.3-contributor';

    /**
     * License keys (as GitLab detects them) that make a repository open source
     */
    private const array OPEN_SOURCE_LICENSES = [
        '0bsd', 'agpl-3.0', 'apache-2.0', 'artistic-2.0', 'bsd-2-clause', 'bsd-3-clause', 'bsd-3-clause-clear', 'bsl-1.0',
        'cc0-1.0', 'epl-1.0', 'epl-2.0', 'eupl-1.2', 'gpl-2.0', 'gpl-3.0', 'isc', 'lgpl-2.1', 'lgpl-3.0', 'mit', 'mpl-2.0',
        'unlicense', 'zlib',
    ];

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
        $info     = $this->info($project);
        $path     = $info !== null ? $info->path : ($project !== null && str_contains($project, '/') ? $project : null);
        $override = $path === null ? null : $this->projects->getByPath($path)?->triageModel;
        $model    = $override ?? '';
        $regular  = (string) getenv('KELPIE_TRIAGE_MODEL') ?: $defaultModel;
        $fallback = '';
        if ($model === '' && $this->isOpenSource($info)) {
            $model    = (string) getenv('KELPIE_TRIAGE_MODEL_PUBLIC') ?: self::DEFAULT_PUBLIC_TRIAGE_MODEL;
            $fallback = $regular === $model || $this->trainsOnPrompts($regular) ? '' : $regular;
        }
        if ($model === '') {
            $model = $regular;
        }

        return new TriageLlmChoice($model, $this->refusalFor($model, $project, $info), $fallback);
    }

    /**
     * Choose the model for an edit proposal: `KELPIE_PROPOSE_MODEL_PUBLIC` for an open-source project when set,
     * else the caller's model; refused like triage when it trains on its prompts and the project is not open source
     *
     * @param ?string $project The GitLab project ID or path with namespace; null when not known
     * @param string  $model   The proposer's own model (`KELPIE_PROPOSE_MODEL` or its default)
     *
     * @return TriageLlmChoice
     */
    public function choosePropose(?string $project, string $model): TriageLlmChoice
    {
        $info   = $this->info($project);
        $public = (string) getenv('KELPIE_PROPOSE_MODEL_PUBLIC');
        if ($public !== '' && $this->isOpenSource($info)) {
            $model = $public;
        }

        return new TriageLlmChoice($model, $this->refusalFor($model, $project, $info));
    }

    /**
     * Whether a project is open source: GitLab detected an OSI open-source license in it
     *
     * @param ?ProjectInfo $info What GitLab said about the project; null when not known
     *
     * @return bool
     */
    public function isOpenSource(?ProjectInfo $info): bool
    {
        return $info !== null && in_array(strtolower($info->license), self::OPEN_SOURCE_LICENSES, true);
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

        $info ??= $this->info($project);
        if ($this->isOpenSource($info)) {
            return '';
        }

        $this->logger->warning('A training-tier model was refused for a project not known to be open source', ['model' => $model, 'project' => $project, 'license' => $info?->license]);

        return sprintf('Refused: %s trains on the prompts it receives, and this project is not known to be open source (no open-source license detected), so its code may not be sent to it.', $model);
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

    /**
     * What GitLab says about the project, or null when no project is given or it cannot be read
     *
     * @param ?string $project The GitLab project ID or path with namespace
     *
     * @return ?ProjectInfo
     */
    private function info(?string $project): ?ProjectInfo
    {
        return $project === null || $project === '' ? null : $this->gitlab->projectInfo()->get($project);
    }
}
