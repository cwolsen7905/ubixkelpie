<?php

declare(strict_types=1);

namespace Kelpie\Tests\Security;

use Kelpie\Security\ScanKind;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Security\ScanKind
 *
 * @coversDefaultClass \Kelpie\Security\ScanKind
 */
final class ScanKindTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ScanKind::class);
    }
}
