<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\ProcessResult;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\ProcessResult
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\ProcessResult
 */
final class ProcessResultTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProcessResult::class);
    }
}
