<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum\Review;

use Kelpie\Enum\Review\Severity;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\Review\Severity
 *
 * @coversDefaultClass \Kelpie\Enum\Review\Severity
 */
final class SeverityTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(Severity::class);
    }

    /**
     * Only a blocker or a major can fail a pipeline
     *
     * @return void
     */
    public function testOnlyBlockerAndMajorCanBlock(): void
    {
        $this->assertTrue(Severity::Blocker->canBlock());
        $this->assertTrue(Severity::Major->canBlock());
        $this->assertFalse(Severity::Minor->canBlock());
        $this->assertFalse(Severity::Nit->canBlock());
    }

    /**
     * The values are the ones the prompt's schema names
     *
     * @return void
     */
    public function testValuesAreThePromptsSchema(): void
    {
        $this->assertSame('blocker', Severity::from('blocker')->value);
        $this->assertNull(Severity::tryFrom('critical'));
    }
}
