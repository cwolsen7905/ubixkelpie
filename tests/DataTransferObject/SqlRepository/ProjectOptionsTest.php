<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\SqlRepository;

use Kelpie\DataTransferObject\SqlRepository\ProjectOptions;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\SqlRepository\ProjectOptions
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\SqlRepository\ProjectOptions
 */
final class ProjectOptionsTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectOptions::class);
    }
}
