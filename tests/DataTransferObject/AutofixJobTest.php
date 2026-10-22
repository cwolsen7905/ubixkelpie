<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\AutofixJob;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\AutofixJob
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\AutofixJob
 */
final class AutofixJobTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixJob::class);
    }

    /**
     * Properties are set and defaults apply
     *
     * @return void
     */
    public function testPropertiesAreSetAndDefaultsApply(): void
    {
        $job = new AutofixJob('org/proj', 7, 'abc', 'note');

        $this->assertSame('org/proj', $job->projectPath);
        $this->assertSame(7, $job->mrIid);
        $this->assertNull($job->discussionId);
        $this->assertFalse($job->afterAutofix);
        $this->assertNull($job->steering);
    }

    /**
     * Steering and the reaction sources are carried
     *
     * @return void
     */
    public function testSteeringAndReactionSources(): void
    {
        $job = new AutofixJob('org/proj', 7, 'abc', 'note', 'd1', false, 'Look at the null case');

        $this->assertSame('Look at the null case', $job->steering);
        $this->assertSame('apply', AutofixJob::APPLY);
        $this->assertSame('decline', AutofixJob::DECLINE);
    }
}
