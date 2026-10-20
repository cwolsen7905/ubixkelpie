<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewUsage;
use Kelpie\Service\Review\ReviewUsageService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\ReviewUsageService
 *
 * @coversDefaultClass \Kelpie\Service\Review\ReviewUsageService
 */
final class ReviewUsageServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewUsageService::class);
    }

    /**
     * Usages add up field by field and the originals are untouched
     *
     * @return void
     */
    public function testPlusAddsFieldByField(): void
    {
        $a = new ReviewUsage(100, 20, 1500);

        $sum = (new ReviewUsageService($this->createStub(Logger::class)))->plus($a, new ReviewUsage(5, 1, 250));

        $this->assertSame([105, 21, 1750], [$sum->tokensIn, $sum->tokensOut, $sum->microCost]);
        $this->assertSame(100, $a->tokensIn);
    }
}
