<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum;

use Kelpie\Enum\IssueTriageClass;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\IssueTriageClass
 *
 * @coversDefaultClass \Kelpie\Enum\IssueTriageClass
 */
final class IssueTriageClassTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueTriageClass::class);
    }

    /**
     * The values are the design's three classes
     *
     * @return void
     */
    public function testValuesAreTheDesignClasses(): void
    {
        $values = [];
        foreach (IssueTriageClass::cases() as $class) {
            $values[] = $class->value;
        }

        $this->assertSame(['fix', 'needs_human', 'not_a_defect'], $values);
    }

    /**
     * Each class has its own kelpie::* label, and none is the gate label
     *
     * @return void
     */
    public function testEachClassHasOneDistinctKelpieLabel(): void
    {
        $labels = [];
        foreach (IssueTriageClass::cases() as $class) {
            $labels[] = $class->label();
            $this->assertStringStartsWith('kelpie::', $class->label());
        }

        $this->assertSame(['kelpie::fixable', 'kelpie::needs-human', 'kelpie::not-a-defect'], $labels);
        $this->assertNotContains('kelpie::fix', $labels);
    }

    /**
     * Each class has its own heading
     *
     * @return void
     */
    public function testEachClassHasAHeading(): void
    {
        $this->assertSame('needs a human', IssueTriageClass::NeedsHuman->heading());
        $this->assertSame('not a defect', IssueTriageClass::NotADefect->heading());
        $this->assertSame('Kelpie could attempt a fix', IssueTriageClass::Fix->heading());
    }
}
