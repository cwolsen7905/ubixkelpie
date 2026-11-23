<?php

declare(strict_types=1);

namespace Kelpie\Tests\Autofix;

use Kelpie\Autofix\Attribution;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Autofix\Attribution
 *
 * What must hold: a commit trailer and a note footer name Kelpie, the agent and the model, and nothing a model id holds can break them.
 *
 * @coversDefaultClass \Kelpie\Autofix\Attribution
 */
final class AttributionTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(Attribution::class);
    }

    /**
     * @return void
     */
    public function testTrailerNamesAgentAndModel(): void
    {
        $this->assertSame('Assisted-by: Kelpie fixer-agent (m/x)', Attribution::Fixer->trailer('m/x'));
    }

    /**
     * @return void
     */
    public function testTrailerWithoutModelLeavesTheParenthesesOut(): void
    {
        $this->assertSame('Assisted-by: Kelpie autofix', Attribution::Autofix->trailer(''));
    }

    /**
     * @return void
     */
    public function testTrailerCannotBeBrokenByTheModelId(): void
    {
        $this->assertSame('Assisted-by: Kelpie autofix (a b)', Attribution::Autofix->trailer("a\n(b)"));
    }

    /**
     * @return void
     */
    public function testFooterOpensWithKelpieAndAgent(): void
    {
        $this->assertSame('_Kelpie · agent `review-agent` · model `m/x`, effort default, 7 in / 3 out._', Attribution::Review->footer('_Model `m/x`, effort default, 7 in / 3 out._'));
    }

    /**
     * @return void
     */
    public function testFooterOfAnotherShapeIsLeftAlone(): void
    {
        $this->assertSame('_No model call._', Attribution::Review->footer('_No model call._'));
    }
}
