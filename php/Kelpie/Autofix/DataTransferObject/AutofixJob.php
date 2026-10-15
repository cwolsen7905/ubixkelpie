<?php

declare(strict_types=1);

namespace Kelpie\Autofix\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One filtered autofix job: an MR head that has something needing resolution
 *
 * @see \Kelpie\Tests\Autofix\DataTransferObject\AutofixJobTest PHPUnit test case
 */
final readonly class AutofixJob implements Dto
{
    /**
     * Constructor
     *
     * @param string  $projectPath  Project path with namespace
     * @param int     $mrIid        Merge request IID
     * @param string  $headSha      Head commit sha of the MR source branch
     * @param string  $source       What raised it: 'note' or 'pipeline'
     * @param ?string $discussionId The thread the note belongs to (note jobs)
     * @param bool    $afterAutofix Whether the head commit is a Kelpie-Autofix commit, so only new threads by someone else may be acted on
     */
    public function __construct(
        public string $projectPath,
        public int $mrIid,
        public string $headSha,
        public string $source,
        public ?string $discussionId = null,
        public bool $afterAutofix = false,
    ) {
    }
}
