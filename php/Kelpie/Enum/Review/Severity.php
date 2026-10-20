<?php

declare(strict_types=1);

namespace Kelpie\Enum\Review;

/**
 * How bad a review finding is
 *
 * @see \Kelpie\Tests\Enum\Review\SeverityTest PHPUnit test case
 */
enum Severity: string
{
    case Blocker = 'blocker';
    case Major   = 'major';
    case Minor   = 'minor';
    case Nit     = 'nit';

    /**
     * Whether a high-confidence finding of this severity can fail a pipeline in blocking mode
     *
     * @return bool
     */
    public function canBlock(): bool
    {
        return $this === self::Blocker || $this === self::Major;
    }
}
