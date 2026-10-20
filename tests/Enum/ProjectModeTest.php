<?php

declare(strict_types=1);

namespace Kelpie\Tests\Enum;

use Kelpie\Enum\ProjectMode;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Enum\ProjectMode
 *
 * What must hold:
 * - The modes are ordered off, propose, push, and each allows what the ones before it allow
 *
 * @coversDefaultClass \Kelpie\Enum\ProjectMode
 */
final class ProjectModeTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the enum is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectMode::class);
    }

    /**
     * The order of the modes
     *
     * @return void
     */
    public function testAllows(): void
    {
        $this->assertTrue(ProjectMode::Push->allows(ProjectMode::Push));
        $this->assertTrue(ProjectMode::Push->allows(ProjectMode::Propose));
        $this->assertTrue(ProjectMode::Propose->allows(ProjectMode::Propose));
        $this->assertFalse(ProjectMode::Propose->allows(ProjectMode::Push));
        $this->assertFalse(ProjectMode::Off->allows(ProjectMode::Propose));
        $this->assertTrue(ProjectMode::Off->allows(ProjectMode::Off));
    }
}
