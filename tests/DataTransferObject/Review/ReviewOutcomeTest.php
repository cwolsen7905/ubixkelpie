<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\Review;

use Kelpie\DataTransferObject\Review\ReviewOutcome;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\Review\ReviewOutcome
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\Review\ReviewOutcome
 */
final class ReviewOutcomeTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewOutcome::class);
    }

    /**
     * 75 is the code of a review that could not run
     *
     * @return void
     */
    public function testUnreviewedIsExitCode75(): void
    {
        $this->assertSame(75, ReviewOutcome::UNREVIEWED);
    }
}
