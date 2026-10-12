<?php

declare(strict_types=1);

namespace Kelpie\Tests\Controller\Api;

use Kelpie\Controller\Api\PreflightController;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Controller\Api\PreflightController
 *
 * @coversDefaultClass \Kelpie\Controller\Api\PreflightController
 */
final class PreflightControllerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(PreflightController::class);
    }
}
