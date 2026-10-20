<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What the review command prints and exits with: 0 reviewed or skipped, 1 blocking, 75 could not run
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ReviewOutcomeTest PHPUnit test case
 */
final readonly class ReviewOutcome implements Dto
{
    public const int UNREVIEWED = 75;

    /**
     * Constructor
     *
     * @param int      $exitCode The exit code
     * @param string[] $lines    The lines to print
     */
    public function __construct(
        public int $exitCode,
        public array $lines = [],
    ) {
    }
}
