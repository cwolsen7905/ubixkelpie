<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use DateTimeInterface;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One note of a GitLab MR discussion
 *
 * @see \Kelpie\Tests\DataTransferObject\DiscussionNoteTest PHPUnit test case
 */
final readonly class DiscussionNote implements Dto
{
    /**
     * Constructor
     *
     * @param int               $id        The note ID
     * @param string            $body      The note body
     * @param string            $author    Author username
     * @param DateTimeInterface $createdAt Creation time
     */
    public function __construct(
        public int $id,
        public string $body,
        public string $author,
        public DateTimeInterface $createdAt,
    ) {
    }
}
