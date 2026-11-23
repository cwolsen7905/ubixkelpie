<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\ProjectSettingsPatch;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\ProjectSettingsPatch
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\ProjectSettingsPatch
 */
final class ProjectSettingsPatchTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectSettingsPatch::class);
    }

    /**
     * Test that the changes are kept as given, a null among them included
     *
     * @return void
     */
    public function testKeepsChanges(): void
    {
        $this->assertSame([], (new ProjectSettingsPatch())->changes);
        $this->assertSame(['max_cost_usd_day_hard' => null], (new ProjectSettingsPatch(['max_cost_usd_day_hard' => null]))->changes);
    }
}
