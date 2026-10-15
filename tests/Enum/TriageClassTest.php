<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum;

use Kelpie\Enum\TriageClass;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\TriageClass
 *
 * @coversDefaultClass \Kelpie\Enum\TriageClass
 */
final class TriageClassTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageClass::class);
    }

    /**
     * The values are the design's class names
     *
     * @return void
     */
    public function testValuesAreTheDesignClasses(): void
    {
        $values = [];
        foreach (TriageClass::cases() as $class) {
            $values[] = $class->value;
        }

        $this->assertSame(['fix', 'needs_human', 'not_an_issue', 'already_fixed'], $values);
    }
}
