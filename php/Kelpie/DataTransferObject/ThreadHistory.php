<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One MR discussion with all its notes, resolved or not
 *
 * @see \Kelpie\Tests\DataTransferObject\ThreadHistoryTest PHPUnit test case
 */
final readonly class ThreadHistory implements Dto
{
    /**
     * Constructor
     *
     * @param string           $discussionId The discussion ID
     * @param bool             $resolvable   Whether the thread can be resolved (a general comment cannot)
     * @param bool             $resolved     Whether the thread is resolved
     * @param DiscussionNote[] $notes        The notes, oldest first, system notes left out
     */
    public function __construct(
        public string $discussionId,
        public bool $resolvable,
        public bool $resolved,
        public array $notes,
    ) {
    }
}
