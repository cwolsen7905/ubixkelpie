<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use DateTimeImmutable;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * An unresolved, resolvable thread from a GitLab MR discussion
 *
 * Immutable DTO — the constructor is the only method.
 *
 * @see \Kelpie\Tests\DataTransferObject\ThreadTest PHPUnit test case
 */
final readonly class Thread implements Dto
{
    /**
     * Constructor
     *
     * @param int               $discussionId The discussion ID
     * @param int               $firstNoteId  The first (original) note ID
     * @param string            $author       Author username
     * @param string            $body         Thread body
     * @param bool              $resolved     Whether the thread is resolved
     * @param int               $noteCount    Total number of notes in the thread
     * @param DateTimeImmutable $createdAt    Creation time
     * @param DateTimeImmutable $updatedAt    Update time
     * @param ?string           $filePath     File path (diff notes only)
     * @param ?int              $line         Line number (diff notes only)
     */
    public function __construct(
        public int $discussionId,
        public int $firstNoteId,
        public string $author,
        public string $body,
        public bool $resolved,
        public int $noteCount,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
        public ?string $filePath = null,
        public ?int $line = null,
    ) {
    }
}
