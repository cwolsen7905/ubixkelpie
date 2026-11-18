<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum;

use Kelpie\Enum\IssueFixOutcome;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\IssueFixOutcome
 *
 * @coversDefaultClass \Kelpie\Enum\IssueFixOutcome
 */
final class IssueFixOutcomeTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueFixOutcome::class);
    }

    /**
     * The value is stored in a VARCHAR(16)
     *
     * @return void
     */
    public function testValuesFitTheColumn(): void
    {
        foreach (IssueFixOutcome::cases() as $case) {
            $this->assertLessThanOrEqual(16, strlen($case->value));
        }
    }
}
