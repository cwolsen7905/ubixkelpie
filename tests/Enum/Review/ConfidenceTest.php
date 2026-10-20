<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum\Review;

use Kelpie\Enum\Review\Confidence;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\Review\Confidence
 *
 * @coversDefaultClass \Kelpie\Enum\Review\Confidence
 */
final class ConfidenceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(Confidence::class);
    }

    /**
     * The values are the ones the prompt's schema names
     *
     * @return void
     */
    public function testValuesAreThePromptsSchema(): void
    {
        $this->assertSame(Confidence::High, Confidence::from('high'));
        $this->assertNull(Confidence::tryFrom('certain'));
    }
}
