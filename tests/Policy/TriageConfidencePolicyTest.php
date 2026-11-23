<?php

declare(strict_types=1);

namespace Kelpie\Tests\Policy;

use Kelpie\DataTransferObject\IssueTriageVerdict;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageConfidence;
use Kelpie\Policy\TriageConfidencePolicy;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Policy\TriageConfidencePolicy
 *
 * What must hold:
 * - A fix at or above the threshold stands; below it, it becomes needs_human with the reason kept
 * - Every other class passes unchanged whatever the confidence
 * - The same holds for an issue verdict
 *
 * @coversDefaultClass \Kelpie\Policy\TriageConfidencePolicy
 */
final class TriageConfidencePolicyTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageConfidencePolicy::class);
    }

    /**
     * With the default threshold a medium or high fix stands and a low one does not
     *
     * @return void
     */
    public function testThreadFixNeedsTheThreshold(): void
    {
        $policy = $this->policy();

        $this->assertSame(TriageClass::Fix, $policy->apply(new TriageVerdict(TriageClass::Fix, 'typo', null, TriageConfidence::High))->class);
        $this->assertSame(TriageClass::Fix, $policy->apply(new TriageVerdict(TriageClass::Fix, 'typo', null, TriageConfidence::Medium))->class);

        $low = $policy->apply(new TriageVerdict(TriageClass::Fix, 'typo', null, TriageConfidence::Low));
        $this->assertSame(TriageClass::NeedsHuman, $low->class);
        $this->assertStringContainsString('low confidence', $low->reason);
        $this->assertStringContainsString('typo', $low->reason);
    }

    /**
     * A stricter threshold holds back a medium fix
     *
     * @return void
     */
    public function testAStricterThresholdHoldsBackMedium(): void
    {
        $policy = new TriageConfidencePolicy($this->createStub(Logger::class), TriageConfidence::High);

        $this->assertSame(TriageClass::NeedsHuman, $policy->apply(new TriageVerdict(TriageClass::Fix, 'x', null, TriageConfidence::Medium))->class);
        $this->assertSame(TriageClass::Fix, $policy->apply(new TriageVerdict(TriageClass::Fix, 'x', null, TriageConfidence::High))->class);
    }

    /**
     * Only a fix is gated: the other classes keep their class at low confidence
     *
     * @return void
     */
    public function testOtherClassesPassUnchanged(): void
    {
        foreach (TriageClass::cases() as $class) {
            if ($class === TriageClass::Fix) {
                continue;
            }
            $verdict = new TriageVerdict($class, 'r', null, TriageConfidence::Low);
            $this->assertSame($verdict, $this->policy()->apply($verdict));
        }
        foreach (IssueTriageClass::cases() as $class) {
            if ($class === IssueTriageClass::Fix) {
                continue;
            }
            $verdict = new IssueTriageVerdict($class, 'r', null, TriageConfidence::Low);
            $this->assertSame($verdict, $this->policy()->applyToIssue($verdict));
        }
    }

    /**
     * An issue fix is gated the same way
     *
     * @return void
     */
    public function testIssueFixNeedsTheThreshold(): void
    {
        $this->assertSame(IssueTriageClass::Fix, $this->policy()->applyToIssue(new IssueTriageVerdict(IssueTriageClass::Fix, 'x', null, TriageConfidence::Medium))->class);

        $low = $this->policy()->applyToIssue(new IssueTriageVerdict(IssueTriageClass::Fix, 'x', null, TriageConfidence::Low));
        $this->assertSame(IssueTriageClass::NeedsHuman, $low->class);
        $this->assertStringContainsString('low confidence', $low->reason);
    }

    /**
     * @return TriageConfidencePolicy
     */
    private function policy(): TriageConfidencePolicy
    {
        return new TriageConfidencePolicy($this->createStub(Logger::class));
    }
}
