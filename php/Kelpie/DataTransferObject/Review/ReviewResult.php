<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * A validated model reply: verdict, summary and findings
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ReviewResultTest PHPUnit test case
 */
final readonly class ReviewResult implements Dto
{
    /**
     * Constructor
     *
     * @param string    $verdict  The verdict: pass, concerns or block
     * @param string    $summary  One to three sentences
     * @param Finding[] $findings The findings
     */
    public function __construct(
        public string $verdict,
        public string $summary,
        public array $findings,
    ) {
    }
}
