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
 * unset, it is the review's own default.
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
     * The model that wrote the review
     *
     * @return string
     */
    public function reviewModel(): string
    {
        return (string) getenv('AI_REVIEW_MODEL') ?: ReviewSettings::DEFAULT_MODEL;
    }

    /**
     * The family of the model that wrote the review
     *
     * @return string
     */
    public function reviewFamily(): string
    {
        return $this->family($this->reviewModel());
    }

    /**
     * Whether a model may not judge the review: same family, or either family unknown
     *
     * @param string $model The model that would triage or fix
     *
     * @return bool
     */
    public function sameAsReview(string $model): bool
    {
        $family = $this->family($model);
        $review = $this->reviewFamily();

        $same = $family === '' || $review === '' || $family === $review;
        if ($same) {
            $this->logger->info('Model is in the review\'s family or cannot be placed', ['model' => $model, 'review_family' => $review]);
        }

        return $same;
    }
}
