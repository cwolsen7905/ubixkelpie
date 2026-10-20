<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One answer of the model: text, or tool calls to run, and what the call cost
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ChatTurnTest PHPUnit test case
 */
final readonly class ChatTurn implements Dto
{
    /**
     * Constructor
     *
     * @param string                                                   $content   The text, empty when the model only called tools
     * @param list<array{id: string, name: string, arguments: string}> $toolCalls The tool calls, in order
     * @param int                                                      $tokensIn  Input tokens of this call
     * @param int                                                      $tokensOut Output tokens of this call
     * @param int                                                      $microCost Cost of this call in micro-dollars
     */
    public function __construct(
        public string $content,
        public array $toolCalls,
        public int $tokensIn,
        public int $tokensOut,
        public int $microCost,
    ) {
    }
}
