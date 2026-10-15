<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum;

use Kelpie\Enum\ResponseOutcome;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\ResponseOutcome
 *
 * @coversDefaultClass \Kelpie\Enum\ResponseOutcome
 */
final class ResponseOutcomeTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ResponseOutcome::class);
    }
}
