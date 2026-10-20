<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewSettings;
use Psr\Log\LoggerInterface as Logger;

/**
 * Reads the review settings from the environment: the AI_REVIEW_* variables and GitLab CI's merge request variables
 *
 * The names and defaults are those of the shell review, so a repo changes nothing when it moves over.
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
     * @param array<string, string> $env        The environment
     * @param bool                  $dryRun     Whether to never call GitLab
     * @param string                $repo       The checkout, empty for AI_REVIEW_REPO, CI_PROJECT_DIR or the working directory
     * @param string                $diffFile   Review this diff file instead of git's
     * @param string                $cannedFile Use this file as the model's reply
     * @param string                $existing   A JSON file of discussions to dedupe against
     *
     * @return ReviewSettings
     */
    public function fromEnvironment(array $env, bool $dryRun = false, string $repo = '', string $diffFile = '', string $cannedFile = '', string $existing = ''): ReviewSettings
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
        );
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
