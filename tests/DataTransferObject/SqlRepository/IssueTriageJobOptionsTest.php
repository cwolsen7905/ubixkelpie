<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject\SqlRepository;

use Kelpie\DataTransferObject\SqlRepository\IssueTriageJobOptions;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\SqlRepository\IssueTriageJobOptions
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\SqlRepository\IssueTriageJobOptions
 */
final class IssueTriageJobOptionsTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueTriageJobOptions::class);
    }
}
