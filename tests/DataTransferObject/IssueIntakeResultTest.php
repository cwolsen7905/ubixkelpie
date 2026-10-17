<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\IssueIntakeResult;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\IssueIntakeResult
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\IssueIntakeResult
 */
final class IssueIntakeResultTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueIntakeResult::class);
    }
}
