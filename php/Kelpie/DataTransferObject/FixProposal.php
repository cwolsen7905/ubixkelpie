<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The facts about a proposed fix that the safety policy checks
 *
 * Built by the fixer (a later step) from the real diff and the MR; never taken from the model's
 * own claims.
 *
 * @see \Kelpie\Tests\DataTransferObject\FixProposalTest PHPUnit test case
 */
final readonly class FixProposal implements Dto
{
    /**
     * Constructor
     *
     * @param int      $changedLines          Added plus removed lines
     * @param string[] $paths                 Repo-relative paths the fix touches
     * @param bool     $deletesOrRenamesFiles Whether any file is deleted or renamed
     * @param bool     $weakensTests          Whether a test is deleted, skipped or its baseline grows
     * @param int      $threadAttempts        Fix attempts already made on this thread
     * @param int      $mrFixCommits          Fix commits already pushed to this MR
     * @param string   $targetBranch          The MR's target branch
     * @param bool     $draft                 Whether the MR is a draft
     */
    public function __construct(
        public int $changedLines,
        public array $paths,
        public bool $deletesOrRenamesFiles,
        public bool $weakensTests,
        public int $threadAttempts,
        public int $mrFixCommits,
        public string $targetBranch,
        public bool $draft,
    ) {
    }
}
