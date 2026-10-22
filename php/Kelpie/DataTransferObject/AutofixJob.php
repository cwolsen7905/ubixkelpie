<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One filtered autofix job: an MR head that has something needing resolution
 *
 * @see \Kelpie\Tests\DataTransferObject\AutofixJobTest PHPUnit test case
 */
final readonly class AutofixJob implements Dto
{
    public const string REVIEW = 'review';

    /**
     * A thumbs-up on a proposal: push its edits
     */
    public const string APPLY = 'apply';

    /**
     * A thumbs-down on a proposal: mark it declined
     */
    public const string DECLINE = 'decline';

    /**
     * Constructor
     *
     * @param string  $projectPath  Project path with namespace
     * @param int     $mrIid        Merge request IID
     * @param string  $headSha      Head commit sha of the MR source branch
     * @param string  $source       What raised it: 'note', 'pipeline', 'review', 'apply' or 'decline'
     * @param ?string $discussionId The thread the note belongs to (note jobs)
     * @param bool    $afterAutofix Whether the head commit is a Kelpie-Autofix commit, so only new threads by someone else may be acted on
     * @param ?string $steering     What the MR author or a maintainer wrote after "Triage:", for the run's prompts
     */
    public function __construct(
        public string $projectPath,
        public int $mrIid,
        public string $headSha,
        public string $source,
        public ?string $discussionId = null,
        public bool $afterAutofix = false,
        public ?string $steering = null,
    ) {
    }
}
