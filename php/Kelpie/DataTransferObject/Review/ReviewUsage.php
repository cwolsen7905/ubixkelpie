<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Tokens and cost of model calls; cost is whole micro-dollars so sums do not drift
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ReviewUsageTest PHPUnit test case
 */
final readonly class ReviewUsage implements Dto
{
    /**
     * Constructor
     *
     * @param int $tokensIn  Input tokens
     * @param int $tokensOut Output tokens
     * @param int $microCost Cost in micro-dollars
     */
    public function __construct(
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $microCost = 0,
    ) {
    }
}
