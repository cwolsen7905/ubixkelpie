<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * A triage run in progress on one merge request: its summary note and the threads it picked up
 *
 * @see \Kelpie\Tests\DataTransferObject\TriageRunTest PHPUnit test case
 */
final readonly class TriageRun implements Dto
{
    /**
     * Constructor
     *
     * @param string   $discussionId The discussion holding the summary note
     * @param int      $noteId       The summary note
     * @param Thread[] $threads      The threads picked up
     */
    public function __construct(
        public string $discussionId,
        public int $noteId,
        public array $threads,
    ) {
    }
}
