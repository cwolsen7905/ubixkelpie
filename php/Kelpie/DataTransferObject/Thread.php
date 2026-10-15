<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use DateTimeInterface;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * An unresolved, resolvable thread from a GitLab MR discussion
 *
 * @see \Kelpie\Tests\DataTransferObject\ThreadTest PHPUnit test case
 */
final readonly class Thread implements Dto
{
    /**
     * Constructor
     *
     * @param string            $discussionId The discussion ID (a GitLab hex string)
     * @param int               $firstNoteId  The first (original) note ID
     * @param string            $author       Author username
     * @param string            $body         Thread body
     * @param bool              $resolved     Whether the thread is resolved
     * @param int               $noteCount    Total number of notes in the thread
     * @param DateTimeInterface $createdAt    Creation time
     * @param DateTimeInterface $updatedAt    Update time
     * @param ?string           $filePath     File path (diff notes only)
     * @param ?int              $line         Line number (diff notes only)
     */
    public function __construct(
        public string $discussionId,
        public int $firstNoteId,
        public string $author,
        public string $body,
        public bool $resolved,
        public int $noteCount,
        public DateTimeInterface $createdAt,
        public DateTimeInterface $updatedAt,
        public ?string $filePath = null,
        public ?int $line = null,
    ) {
    }
}
