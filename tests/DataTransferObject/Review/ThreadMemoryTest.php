<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\Review;

use Kelpie\DataTransferObject\Review\ThreadMemory;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\Review\ThreadMemory
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\Review\ThreadMemory
 */
final class ThreadMemoryTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ThreadMemory::class);
    }
}
