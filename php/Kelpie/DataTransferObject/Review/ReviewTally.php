<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What posting a review's findings did, for the summary note and the exit code
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ReviewTallyTest PHPUnit test case
 */
final readonly class ReviewTally implements Dto
{
    /**
     * Constructor
     *
     * @param int      $posted    New threads opened
     * @param int      $dismissed Findings a person had dismissed
     * @param int      $stillOpen Open threads of the review that its lines did not move on
     * @param int      $failed    Comments GitLab refused
     * @param int      $blocking  Standing blocker or major findings with high confidence
     * @param string[] $fileLevel Summary lines for findings not tied to one line
     * @param string[] $sameLine  Summary lines for findings on a line an open thread already covers
     * @param string[] $stale     Summary lines for open threads whose lines changed and that the run no longer reports
     * @param string[] $listed    Summary lines for new findings a shadow run lists instead of opening threads
     * @param string[] $output    What a dry run would post, for printing
     */
    public function __construct(
        public int $posted = 0,
        public int $dismissed = 0,
        public int $stillOpen = 0,
        public int $failed = 0,
        public int $blocking = 0,
        public array $fileLevel = [],
        public array $sameLine = [],
        public array $stale = [],
        public array $listed = [],
        public array $output = [],
    ) {
    }
}
