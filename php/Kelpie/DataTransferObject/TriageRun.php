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
     * @param int      $noteId  The summary note, a plain MR note
     * @param Thread[] $threads The threads picked up
     */
    public function __construct(
        public int $noteId,
        public array $threads,
    ) {
    }
}
