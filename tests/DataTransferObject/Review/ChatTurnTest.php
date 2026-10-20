<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\Review;

use Kelpie\DataTransferObject\Review\ChatTurn;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\Review\ChatTurn
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\Review\ChatTurn
 */
final class ChatTurnTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ChatTurn::class);
    }
}
