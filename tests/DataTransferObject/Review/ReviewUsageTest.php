<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\Review;

use Kelpie\DataTransferObject\Review\ReviewUsage;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\Review\ReviewUsage
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\Review\ReviewUsage
 */
final class ReviewUsageTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewUsage::class);
    }
}
