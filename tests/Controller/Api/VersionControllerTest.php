<?php

declare(strict_types=1);

namespace Kelpie\Tests\Controller\Api;

use Kelpie\Controller\Api\VersionController;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Controller\Api\VersionController
 *
 * @coversDefaultClass \Kelpie\Controller\Api\VersionController
 */
final class VersionControllerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(VersionController::class);
    }
}
