<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\Review\Finding
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\Review\Finding
 */
final class FindingTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(Finding::class);
    }
}
