<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\Review\ReviewSettings;
use Psr\Log\LoggerInterface as Logger;

/**
 * Which model family a model id belongs to, and whether it is the family that wrote the review
 *
 * A model that fixes or judges a finding must not come from the family that wrote it (a second opinion
 * only counts when it is independent). The family is the vendor part of an OpenRouter id
 * (`anthropic/claude-haiku-5.5` is `anthropic`), after an `openrouter/` routing prefix. An id with no vendor
 * part cannot be placed, so it is never trusted to be different.
 *
 * The review's model is `AI_REVIEW_MODEL` from the worker's environment, which must match the review job's;
 * unset, it is the review's own default. For an open-source project the review job uses `AI_REVIEW_MODEL_PUBLIC`
 * when set, and falls back to the regular model when that one gives no answer (`ReviewSettingsService::forLicense`),
 * so either may have written it. The guard therefore refuses both families for such a project, and also for a
 * project whose license could not be read (`$openSource` null): it fails closed rather than guessing.
 *
 * @see \Kelpie\Tests\Service\Triage\LlmFamilyServiceTest PHPUnit test case
 */
final class LlmFamilyService
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
     * The family of a model id
     *
     * @param string $model A model id, e.g. `openrouter/anthropic/claude-haiku-5.5`
     *
     * @return string The lower-case family, empty when the id has no vendor part
     */
    public function family(string $model): string
    {
        $id = strtolower(trim($model));
        if (str_starts_with($id, 'openrouter/')) {
            $id = substr($id, strlen('openrouter/'));
        }

        $parts = explode('/', $id);

        return count($parts) > 1 ? $parts[0] : '';
    }

    /**
     * The models that may have written the review
     *
     * @param ?bool $openSource Whether the project is open source; null when its license could not be read
     *
     * @return list<string> The regular model, preceded by `AI_REVIEW_MODEL_PUBLIC` when it is set and the project is
     *                      (or may be) open source
     */
    public function reviewModels(?bool $openSource = false): array
    {
        $regular = (string) getenv('AI_REVIEW_MODEL') ?: ReviewSettings::DEFAULT_MODEL;
        $public  = $openSource === false ? '' : (string) getenv('AI_REVIEW_MODEL_PUBLIC');

        return $public !== '' && $public !== $regular ? [$public, $regular] : [$regular];
    }

    /**
     * The families that may have written the review, for an audit row
     *
     * @param ?bool $openSource Whether the project is open source; null when its license could not be read
     *
     * @return string The families joined by `+` (e.g. `meta+anthropic`), one when only one model applies
     */
    public function reviewFamily(?bool $openSource = false): string
    {
        return implode('+', array_values(array_unique(array_map($this->family(...), $this->reviewModels($openSource)))));
    }

    /**
     * Whether a model may not judge the review: in a family that may have written it, or a family unknown
     *
     * @param string $model      The model that would triage or fix
     * @param ?bool  $openSource Whether the project is open source; null when its license could not be read
     *
     * @return bool
     */
    public function sameAsReview(string $model, ?bool $openSource = false): bool
    {
        $family   = $this->family($model);
        $families = array_map($this->family(...), $this->reviewModels($openSource));

        $same = $family === '' || in_array('', $families, true) || in_array($family, $families, true);
        if ($same) {
            $this->logger->info('Model is in a review family or cannot be placed', ['model' => $model, 'review_family' => implode('+', $families)]);
        }

        return $same;
    }
}
