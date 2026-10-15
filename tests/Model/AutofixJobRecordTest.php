<?php

declare(strict_types=1);

namespace Kelpie\Tests\Model;

use Kelpie\Model\AutofixJobRecord;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Model\AutofixJobRecord
 *
 * @coversDefaultClass \Kelpie\Model\AutofixJobRecord
 */
final class AutofixJobRecordTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixJobRecord::class);
    }

    /**
     * A job is open while pending or running only
     *
     * @return void
     */
    public function testIsOpen(): void
    {
        $this->assertTrue((new AutofixJobRecord(projectPath: 'p', mrIid: 1, headSha: 'a', source: 'note', status: 'pending'))->isOpen());
        $this->assertTrue((new AutofixJobRecord(projectPath: 'p', mrIid: 1, headSha: 'a', source: 'note', status: 'running'))->isOpen());
        $this->assertFalse((new AutofixJobRecord(projectPath: 'p', mrIid: 1, headSha: 'a', source: 'note', status: 'superseded'))->isOpen());
    }
}
