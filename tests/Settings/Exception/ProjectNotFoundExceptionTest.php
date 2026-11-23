<?php

declare(strict_types=1);

namespace Kelpie\Tests\Settings\Exception;

use Kelpie\Settings\Exception\ProjectNotFoundException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Settings\Exception\ProjectNotFoundException
 *
 * @coversDefaultClass \Kelpie\Settings\Exception\ProjectNotFoundException
 */
#[AllowMockObjectsWithoutExpectations]
final class ProjectNotFoundExceptionTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectNotFoundException::class);
    }

    /**
     * Test that the message names the project
     *
     * @return void
     */
    public function testMessageNamesTheProject(): void
    {
        $this->assertSame('No project a/b', new ProjectNotFoundException('No project a/b')->getMessage());
    }
}
