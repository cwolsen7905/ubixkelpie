<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\SqlRepository;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Criteria for finding thread outcome rows: a thread's row, or the row whose proposal is a given note
 *
 * @see \Kelpie\Tests\DataTransferObject\SqlRepository\ThreadOutcomeOptionsTest PHPUnit test case
 */
final readonly class ThreadOutcomeOptions implements Dto
{
    /**
     * Constructor
     *
     * @param string  $projectPath    The project path with namespace
     * @param int     $mrIid          The MR IID
     * @param ?string $discussionId   The discussion ID, when looking a thread up
     * @param ?int    $proposalNoteId The note carrying the proposal, when looking a proposal up
     */
    public function __construct(
        public string $projectPath,
        public int $mrIid,
        public ?string $discussionId = null,
        public ?int $proposalNoteId = null,
    ) {
    }
}
