<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One open merge request as the sweep lists it
 *
 * @see \Kelpie\Tests\DataTransferObject\MergeRequestSummaryTest PHPUnit test case
 */
final readonly class MergeRequestSummary implements Dto
{
    /**
     * Constructor
     *
     * @param int    $projectId      The GitLab project ID
     * @param int    $iid            The MR IID
     * @param string $title          The title
     * @param bool   $draft          Whether the MR is a draft
     * @param string $sourceBranch   The source branch
     * @param string $targetBranch   The target branch
     * @param string $sha            Head commit sha of the source branch
     * @param ?int   $pipelineId     The head pipeline ID, when there is one
     * @param string $pipelineStatus The head pipeline status, empty when there is none
     * @param string $pipelineUser   The user the head pipeline ran as, empty when unknown
     */
    public function __construct(
        public int $projectId,
        public int $iid,
        public string $title,
        public bool $draft,
        public string $sourceBranch,
        public string $targetBranch,
        public string $sha,
        public ?int $pipelineId = null,
        public string $pipelineStatus = '',
        public string $pipelineUser = '',
    ) {
    }
}
