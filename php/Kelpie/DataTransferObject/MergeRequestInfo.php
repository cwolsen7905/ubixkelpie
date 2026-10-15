<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The facts about a merge request that decide whether the fixer may push to it
 *
 * @see \Kelpie\Tests\DataTransferObject\MergeRequestInfoTest PHPUnit test case
 */
final readonly class MergeRequestInfo implements Dto
{
    /**
     * Constructor
     *
     * @param string $sha             Head commit sha of the source branch
     * @param string $sourceBranch    The source branch
     * @param string $targetBranch    The target branch
     * @param bool   $draft           Whether the MR is a draft
     * @param bool   $open            Whether the MR is open
     * @param int    $sourceProjectId The project the source branch lives in
     * @param int    $targetProjectId The project the MR targets
     * @param string $webUrl          The MR's web URL, empty when GitLab did not say
     */
    public function __construct(
        public string $sha,
        public string $sourceBranch,
        public string $targetBranch,
        public bool $draft,
        public bool $open,
        public int $sourceProjectId,
        public int $targetProjectId,
        public string $webUrl = '',
    ) {
    }
}
