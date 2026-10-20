<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Everything one review run is told: the AI_REVIEW_* settings, the merge request and the files it reads
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ReviewSettingsTest PHPUnit test case
 */
final readonly class ReviewSettings implements Dto
{
    public const string DEFAULT_MODEL = 'openrouter/anthropic/claude-haiku-5.5';

    /**
     * Constructor
     *
     * @param bool     $blocking      Blocking mode: exit 1 on a standing high-confidence blocker or major finding
     * @param bool     $shadow        Shadow run: only a labelled summary note is posted, never a thread, and it never fails
     * @param bool     $dryRun        Never call GitLab; print what would be posted
     * @param string   $model         OpenRouter model as `openrouter/<id>`, as the summary stamp prints it
     * @param string   $apiModel      The same model as the API names it, without the `openrouter/` prefix
     * @param string   $effort        Reasoning effort, empty for the provider default
     * @param int      $maxLines      Diff line cap
     * @param int      $maxBytes      Diff byte cap
     * @param int      $maxRounds     Rounds per merge request
     * @param string   $extraLabel    The label that allows more rounds
     * @param int      $maxTurns      Model calls per review, the last of which has no tools
     * @param string   $apiUrl        GitLab API base URL
     * @param string   $token         The review's GitLab token
     * @param string   $project       GitLab project ID
     * @param int      $mrIid         Merge request IID, 0 when not in a merge request pipeline
     * @param string   $title         Merge request title
     * @param string[] $labels        Merge request labels
     * @param string   $baseSha       The merge request's diff base commit
     * @param string   $headSha       The commit under review
     * @param string   $repo          The checkout
     * @param string   $summaryMarker Start of this run's summary note; a shadow run's differs so the two engines never replace each other's note
     * @param string   $diffFile      Review this diff file instead of git's (no merge request needed)
     * @param string   $cannedFile    Use this file as the model's reply
     * @param string   $existing      A JSON file of discussions to dedupe against, instead of the API
     */
    public function __construct(
        public bool $blocking,
        public bool $shadow,
        public bool $dryRun,
        public string $model,
        public string $apiModel,
        public string $effort,
        public int $maxLines,
        public int $maxBytes,
        public int $maxRounds,
        public string $extraLabel,
        public int $maxTurns,
        public string $apiUrl,
        public string $token,
        public string $project,
        public int $mrIid,
        public string $title,
        public array $labels,
        public string $baseSha,
        public string $headSha,
        public string $repo,
        public string $summaryMarker = '<!-- ai-review:summary',
        public string $diffFile = '',
        public string $cannedFile = '',
        public string $existing = '',
    ) {
    }
}
