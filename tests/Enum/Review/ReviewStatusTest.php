<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum\Review;

use Kelpie\Enum\Review\ReviewStatus;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\Review\ReviewStatus
 *
 * What must hold:
 * - Every status is a `kelpie-review::` label with a colour and a description
 * - othersThan() names every other status, so setting one takes the rest off
 *
 * @coversDefaultClass \Kelpie\Enum\Review\ReviewStatus
 */
final class ReviewStatusTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewStatus::class);
    }

    /**
     * Every status is one scoped label, coloured and described
     *
     * @return void
     */
    public function testEveryStatusIsAScopedLabel(): void
    {
        foreach (ReviewStatus::cases() as $status) {
            $this->assertStringStartsWith('kelpie-review::', $status->value);
            $this->assertMatchesRegularExpression('~^#[0-9a-f]{6}$~', $status->colour());
            $this->assertNotSame('', $status->description());
        }
    }

    /**
     * The others are every status but the one kept
     *
     * @return void
     */
    public function testOthersThan(): void
    {
        $others = ReviewStatus::othersThan(ReviewStatus::Clean);

        $this->assertCount(count(ReviewStatus::cases()) - 1, $others);
        $this->assertNotContains('kelpie-review::clean', $others);
        $this->assertContains('kelpie-review::reviewing', $others);
    }
}
