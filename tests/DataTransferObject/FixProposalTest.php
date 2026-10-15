<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\FixProposal;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\FixProposal
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\FixProposal
 */
final class FixProposalTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FixProposal::class);
    }
}
