<?php

declare(strict_types=1);

namespace Kelpie\Tests\Autofix;

use Kelpie\Autofix\Attribution;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Autofix\Attribution
 *
 * What must hold: a commit trailer and a note footer name Kelpie, the agent and the model; a note footer also names the build (a short sha, the value as it is, or `(unknown build)`); and nothing a model id holds can break them.
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
        $this->assertSame('_' . Attribution::signature() . ' · agent `review-agent` · model `m/x`, effort default, 7 in / 3 out._', Attribution::Review->footer('_Model `m/x`, effort default, 7 in / 3 out._'));
    }

    /**
     * @return void
     */
    public function testFooterNamesTheBuild(): void
    {
        $this->withAppVersion('0123456789abcdef0123456789abcdef01234567', function (): void {
            $this->assertSame('_Kelpie 0123456 · agent `triage-model` · model `m/x`, 7 in / 3 out._', Attribution::Triage->footer('_Model `m/x`, 7 in / 3 out._'));
        });
    }

    /**
     * @return void
     */
    public function testSignatureCutsAShaToSevenCharacters(): void
    {
        $this->assertSame('Kelpie abcdef1', Attribution::signature('abcdef1234567890'));
        $this->assertSame('Kelpie ABCDEF1', Attribution::signature('ABCDEF1'));
    }

    /**
     * @return void
     */
    public function testSignatureShowsAnotherValueAsItIs(): void
    {
        $this->assertSame('Kelpie v0.1.0-beta.3', Attribution::signature('v0.1.0-beta.3'));
        $this->assertSame('Kelpie abc12', Attribution::signature('abc12'));
        $this->assertSame('Kelpie a b', Attribution::signature("a`\nb"));
    }

    /**
     * @return void
     */
    public function testSignatureOfAnUnknownBuild(): void
    {
        $this->assertSame('Kelpie (unknown build)', Attribution::signature('unknown'));
        $this->assertSame('Kelpie (unknown build)', Attribution::signature('UNKNOWN'));
        $this->assertSame('Kelpie (unknown build)', Attribution::signature(''));
        $this->assertSame('Kelpie (unknown build)', Attribution::signature('  '));
    }

    /**
     * @return void
     */
    public function testSignatureReadsAppVersion(): void
    {
        $this->withAppVersion('fedcba9876543210', function (): void {
            $this->assertSame('Kelpie fedcba9', Attribution::signature());
        });
        $this->withAppVersion('unknown', function (): void {
            $this->assertSame('Kelpie (unknown build)', Attribution::signature());
        });
        $this->withAppVersion('', function (): void {
            $this->assertSame('Kelpie (unknown build)', Attribution::signature());
        });
        $this->withAppVersion(null, function (): void {
            $this->assertSame('Kelpie (unknown build)', Attribution::signature());
        });
    }

    /**
     * @return void
     */
    public function testFooterOfAnotherShapeIsLeftAlone(): void
    {
        $this->assertSame('_No model call._', Attribution::Review->footer('_No model call._'));
    }

    /**
     * Run a check with `APP_VERSION` set (null: unset), then put it back as it was
     *
     * @param ?string  $value The value
     * @param callable $check The check
     *
     * @return void
     */
    private function withAppVersion(?string $value, callable $check): void
    {
        $before = getenv('APP_VERSION');
        putenv($value === null ? 'APP_VERSION' : 'APP_VERSION=' . $value);
        try {
            $check();
        } finally {
            putenv($before === false ? 'APP_VERSION' : 'APP_VERSION=' . $before);
        }
    }
}
