<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum;

use Kelpie\Enum\ThreadOutcome;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\ThreadOutcome
 *
 * @coversDefaultClass \Kelpie\Enum\ThreadOutcome
 */
final class ThreadOutcomeTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ThreadOutcome::class);
    }

    /**
     * The values are what the outcome column stores
     *
     * @return void
     */
    public function testValuesAreWhatTheColumnStores(): void
    {
        $values = [];
        foreach (ThreadOutcome::cases() as $outcome) {
            $values[] = $outcome->value;
        }

        $this->assertSame(['proposed', 'answered', 'applied', 'declined', 'overridden', 'reverted', 'reopened', 'failed'], $values);
    }
}
