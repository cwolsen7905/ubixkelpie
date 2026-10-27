<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\AutofixSettings;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\AutofixSettings
 *
 * What must hold:
 * - With nothing given, autofix is off and the defaults are the safe ones: one project, the default model
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\AutofixSettings
 */
final class AutofixSettingsTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixSettings::class);
    }

    /**
     * The defaults
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $settings = new AutofixSettings();

        $this->assertFalse($settings->enabled);
        $this->assertSame('anthropic/claude-sonnet-5.5', $settings->fixerModel);
        $this->assertSame(1800, $settings->claimTtl);
        $this->assertSame(5, $settings->sweepMaxRuns);
        $this->assertSame('kelpie-bot', $settings->botUsername);
    }
}
