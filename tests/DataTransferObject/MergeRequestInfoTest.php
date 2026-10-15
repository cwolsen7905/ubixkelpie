<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\MergeRequestInfo;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\MergeRequestInfo
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\MergeRequestInfo
 */
final class MergeRequestInfoTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MergeRequestInfo::class);
    }
}
