<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewUsage;
use Psr\Log\LoggerInterface as Logger;

/**
 * Sums the tokens and cost of a review's model calls
 *
 * @see \Kelpie\Tests\Service\Review\ReviewUsageServiceTest PHPUnit test case
 */
final class ReviewUsageService
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first; adding has nothing to log)
    ) {
    }

    /**
     * Two usages added field by field; neither is changed
     *
     * @param ReviewUsage $a One usage
     * @param ReviewUsage $b Another
     *
     * @return ReviewUsage
     */
    public function plus(ReviewUsage $a, ReviewUsage $b): ReviewUsage
    {
        return new ReviewUsage($a->tokensIn + $b->tokensIn, $a->tokensOut + $b->tokensOut, $a->microCost + $b->microCost);
    }
}
