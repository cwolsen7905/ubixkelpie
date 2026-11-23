<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum;

use Kelpie\Enum\TriageConfidence;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\TriageConfidence
 *
 * @coversDefaultClass \Kelpie\Enum\TriageConfidence
 */
final class TriageConfidenceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageConfidence::class);
    }

    /**
     * A missing, non-string or unknown answer reads as low; a known word is read whatever its case
     *
     * @return void
     */
    public function testFromAnswer(): void
    {
        $this->assertSame(TriageConfidence::Low, TriageConfidence::fromAnswer(null));
        $this->assertSame(TriageConfidence::Low, TriageConfidence::fromAnswer(0.9));
        $this->assertSame(TriageConfidence::Low, TriageConfidence::fromAnswer('certain'));
        $this->assertSame(TriageConfidence::High, TriageConfidence::fromAnswer(' High '));
        $this->assertSame(TriageConfidence::Medium, TriageConfidence::fromAnswer('medium'));
    }

    /**
     * The order is low, medium, high
     *
     * @return void
     */
    public function testAtLeast(): void
    {
        $this->assertTrue(TriageConfidence::High->atLeast(TriageConfidence::Medium));
        $this->assertTrue(TriageConfidence::Medium->atLeast(TriageConfidence::Medium));
        $this->assertFalse(TriageConfidence::Low->atLeast(TriageConfidence::Medium));
        $this->assertTrue(TriageConfidence::Low->atLeast(TriageConfidence::Low));
    }
}
