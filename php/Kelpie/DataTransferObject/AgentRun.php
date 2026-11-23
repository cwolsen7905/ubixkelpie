<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What one run of the fixing agent came to: whether it finished, and what it cost even when it did not
 *
 * @see \Kelpie\Tests\DataTransferObject\AgentRunTest PHPUnit test case
 */
final readonly class AgentRun implements Dto
{
    /**
     * Constructor
     *
     * @param bool        $finished   Whether the agent ran to completion
     * @param ?Completion $completion Its closing words, model, tokens and cost; null only when no model call was made
     */
    public function __construct(
        public bool $finished,
        public ?Completion $completion = null,
    ) {
    }
}
