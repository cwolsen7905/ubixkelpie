<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageConfidence;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\TriageVerdict
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\TriageVerdict
 */
final class TriageVerdictTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageVerdict::class);
    }

    /**
     * Test that confidence defaults to Low when not supplied
     *
     * @return void
     */
    public function testConfidenceDefaultsToLow(): void
    {
        $verdict = new TriageVerdict(TriageClass::Fix, 'reason');

        self::assertSame(TriageConfidence::Low, $verdict->confidence);
    }
}
