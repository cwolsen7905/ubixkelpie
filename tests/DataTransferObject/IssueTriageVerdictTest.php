<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\IssueTriageVerdict;
use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\TriageConfidence;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\IssueTriageVerdict
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\IssueTriageVerdict
 */
final class IssueTriageVerdictTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueTriageVerdict::class);
    }

    /**
     * Test that confidence defaults to Low when not supplied
     *
     * @return void
     */
    public function testConfidenceDefaultsToLow(): void
    {
        $verdict = new IssueTriageVerdict(IssueTriageClass::Fix, 'reason');

        self::assertSame(TriageConfidence::Low, $verdict->confidence);
    }
}
