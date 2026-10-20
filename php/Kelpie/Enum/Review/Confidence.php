<?php

declare(strict_types=1);

namespace Kelpie\Enum\Review;

/**
 * How sure the model is of a review finding; high means it checked the claim in the code
 *
 * @see \Kelpie\Tests\Enum\Review\ConfidenceTest PHPUnit test case
 */
enum Confidence: string
{
    case High   = 'high';
    case Medium = 'medium';
    case Low    = 'low';
}
