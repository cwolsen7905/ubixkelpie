<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\ProjectInfo;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Psr\Log\LoggerInterface as Logger;

/**
 * Reads the review settings from the environment: the AI_REVIEW_* variables and GitLab CI's merge request variables
 *
 * The names and defaults are those of the shell review, so a repo changes nothing when it moves over.
 *
 * `AI_REVIEW_MODEL_PUBLIC`, when set, replaces `AI_REVIEW_MODEL` for a project whose repository GitLab detects an
 * open-source license in (`ProjectInfo::OPEN_SOURCE_LICENSES`): such a model may be a tier that trains on the code
 * it reads. A license that could not be read keeps the regular model. The regular model stays behind the
 * open-source one as `fallbackModel`, used when the open-source one gives no valid review. The worker applies the same rule
 * (`LlmFamilyService`), so it knows which family wrote a project's review.
 *
 * @see \Kelpie\Tests\Service\Review\ReviewSettingsServiceTest PHPUnit test case
 */
final class ReviewSettingsService
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * The settings of an environment
     *
     * @param array<string, string> $env           The environment
     * @param bool                  $dryRun        Whether to never call GitLab
     * @param string                $repo          The checkout, empty for AI_REVIEW_REPO, CI_PROJECT_DIR or the working directory
     * @param string                $diffFile      Review this diff file instead of git's
     * @param string                $cannedFile    Use this file as the model's reply
     * @param string                $existing      A JSON file of discussions to dedupe against
     * @param string                $fallbackModel The model to fall back to when the model gives no valid review
     *
     * @return ReviewSettings
     */
    public function fromEnvironment(array $env, bool $dryRun = false, string $repo = '', string $diffFile = '', string $cannedFile = '', string $existing = '', string $fallbackModel = ''): ReviewSettings
    {
        $this->logger->debug('Reading the review settings from the environment');

        $labels = array_values(array_filter(array_map('trim', explode(',', $env['CI_MERGE_REQUEST_LABELS'] ?? ''))));

        $model  = $this->text($env, 'AI_REVIEW_MODEL', ReviewSettings::DEFAULT_MODEL);
        $shadow = ($env['AI_REVIEW_SHADOW'] ?? '') === 'true';

        return new ReviewSettings(
            blocking:      ($env['AI_REVIEW_MODE'] ?? '') === 'blocking',
            shadow:        $shadow,
            dryRun:        $dryRun,
            model:         $model,
            apiModel:      str_starts_with($model, 'openrouter/') ? substr($model, strlen('openrouter/')) : $model,
            effort:        $env['AI_REVIEW_EFFORT'] ?? '',
            maxLines:      $this->number($env, 'AI_REVIEW_MAX_LINES', 3000),
            maxBytes:      $this->number($env, 'AI_REVIEW_MAX_BYTES', 153600),
            maxRounds:     $this->number($env, 'AI_REVIEW_MAX_ROUNDS', 3),
            extraLabel:    $this->text($env, 'AI_REVIEW_EXTRA_LABEL', 'review:extra'),
            maxTurns:      $this->number($env, 'AI_REVIEW_MAX_TURNS', 12),
            apiUrl:        rtrim($env['CI_API_V4_URL'] ?? '', '/'),
            token:         $env['AI_REVIEW_GITLAB_TOKEN'] ?? '',
            project:       $env['CI_PROJECT_ID'] ?? '',
            mrIid:         (int) ($env['CI_MERGE_REQUEST_IID'] ?? 0),
            title:         $env['CI_MERGE_REQUEST_TITLE'] ?? '',
            labels:        $labels,
            baseSha:       $env['CI_MERGE_REQUEST_DIFF_BASE_SHA'] ?? '',
            headSha:       $this->text($env, 'CI_COMMIT_SHA', 'HEAD'),
            repo:          $repo !== '' ? $repo : $this->text($env, 'AI_REVIEW_REPO', $this->text($env, 'CI_PROJECT_DIR', (string) getcwd())),
            summaryMarker: $shadow ? '<!-- kelpie-review:shadow' : '<!-- ai-review:summary',
            diffFile:      $diffFile,
            cannedFile:    $cannedFile,
            existing:      $existing,
            fallbackModel: $fallbackModel,
        );
    }

    /**
     * The settings for the project's license: the open-source model when one is set and the license is open source
     *
     * @param ReviewSettings        $settings The settings from `fromEnvironment()`
     * @param array<string, string> $env      The same environment
     * @param ?string               $license  The license key GitLab detected, empty for none, null when not read
     *
     * @return ReviewSettings
     */
    public function forLicense(ReviewSettings $settings, array $env, ?string $license): ReviewSettings
    {
        $public = $env['AI_REVIEW_MODEL_PUBLIC'] ?? '';
        if ($public === '' || !$this->isOpenSource($license)) {
            return $settings;
        }

        $this->logger->info('Open-source project: reviewing with the open-source model', ['license' => $license, 'model' => $public]);

        // A training tier the OpenRouter account's data policy excludes answers 404 on every call: keep the regular
        // model behind it, so the merge request is still reviewed rather than left unreviewed.
        $fallback = $public === $settings->model ? '' : $settings->model;

        return $this->fromEnvironment(['AI_REVIEW_MODEL' => $public] + $env, $settings->dryRun, $settings->repo, $settings->diffFile, $settings->cannedFile, $settings->existing, $fallback);
    }

    /**
     * Whether a license key is an open-source license
     *
     * @param ?string $license The license key GitLab detected, empty for none, null when not read
     *
     * @return bool
     */
    public function isOpenSource(?string $license): bool
    {
        return $license !== null && in_array(strtolower($license), ProjectInfo::OPEN_SOURCE_LICENSES, true);
    }

    /**
     * Whether the mode variable names a mode the review knows
     *
     * @param array<string, string> $env The environment
     *
     * @return bool
     */
    public function modeIsValid(array $env): bool
    {
        return in_array($env['AI_REVIEW_MODE'] ?? 'advisory', ['', 'advisory', 'blocking'], true);
    }

    /**
     * @param array<string, string> $env     The environment
     * @param string                $name    The variable
     * @param string                $default Used when the variable is missing or empty
     *
     * @return string
     */
    private function text(array $env, string $name, string $default): string
    {
        $value = $env[$name] ?? '';

        return $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, string> $env     The environment
     * @param string                $name    The variable
     * @param int                   $default Used when the variable is missing or not a positive number
     *
     * @return int
     */
    private function number(array $env, string $name, int $default): int
    {
        $value = (int) ($env[$name] ?? 0);

        return $value > 0 ? $value : $default;
    }
}
