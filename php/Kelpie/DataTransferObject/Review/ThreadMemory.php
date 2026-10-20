<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What the merge request already holds: open and dismissed finding threads and the review's own summary note
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ThreadMemoryTest PHPUnit test case
 */
final readonly class ThreadMemory implements Dto
{
    /**
     * Constructor
     *
     * @param ThreadRow[] $open          Threads nobody has resolved
     * @param ThreadRow[] $dismissed     Threads resolved with a person's `Dismissed:` reply
     * @param ?int        $summaryNoteId The review's summary note, null when there is none yet
     * @param int         $summaryRound  The round that note records
     * @param string      $summarySha    The commit that note records
     */
    public function __construct(
        public array $open = [],
        public array $dismissed = [],
        public ?int $summaryNoteId = null,
        public int $summaryRound = 0,
        public string $summarySha = '',
    ) {
    }
}
