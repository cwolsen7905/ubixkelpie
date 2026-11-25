<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum\Review;

use Kelpie\Enum\Review\FindingsLevel;
use Kelpie\Enum\Review\Severity;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\Review\FindingsLevel
 *
 * What must hold:
 * - The level follows the worst open severity; minor and nit are both minor-only; nothing open is none
 * - Every level is a `kelpie-findings::` label with a colour and a description, and othersThan() names the rest
 *
 * @coversDefaultClass \Kelpie\Enum\Review\FindingsLevel
 */
final class FindingsLevelTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FindingsLevel::class);
    }

    /**
     * The worst severity picks the level
     *
     * @return void
     */
    public function testFromWorst(): void
    {
        $this->assertSame(FindingsLevel::Blocker, FindingsLevel::fromWorst(Severity::Blocker));
        $this->assertSame(FindingsLevel::Major, FindingsLevel::fromWorst(Severity::Major));
        $this->assertSame(FindingsLevel::MinorOnly, FindingsLevel::fromWorst(Severity::Minor));
        $this->assertSame(FindingsLevel::MinorOnly, FindingsLevel::fromWorst(Severity::Nit));
        $this->assertSame(FindingsLevel::None, FindingsLevel::fromWorst(null));
    }

    /**
     * Every level is one scoped label, coloured and described, and the others are the rest
     *
     * @return void
     */
    public function testEveryLevelIsAScopedLabel(): void
    {
        foreach (FindingsLevel::cases() as $level) {
            $this->assertStringStartsWith('kelpie-findings::', $level->value);
            $this->assertMatchesRegularExpression('~^#[0-9a-f]{6}$~', $level->colour());
            $this->assertNotSame('', $level->description());
        }

        $others = FindingsLevel::othersThan(FindingsLevel::None);
        $this->assertCount(3, $others);
        $this->assertNotContains('kelpie-findings::none', $others);
    }
}
