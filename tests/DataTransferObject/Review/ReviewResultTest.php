<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\Review;

use Kelpie\DataTransferObject\Review\ReviewResult;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\Review\ReviewResult
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\Review\ReviewResult
 */
final class ReviewResultTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewResult::class);
    }
}
