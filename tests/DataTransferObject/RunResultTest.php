<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use Kelpie\DataTransferObject\RunResult;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\RunResult
 *
 * What must hold:
 * - It carries the success flag, the lines and the error flag it was given
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\RunResult
 */
final class RunResultTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(RunResult::class);
    }

    /**
     * The flags default to a plain, empty, successful run
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $result = new RunResult(true);

        $this->assertTrue($result->success);
        $this->assertFalse($result->isError);
        $this->assertSame([], $result->lines);
    }

    /**
     * What it was given is what it carries
     *
     * @return void
     */
    public function testCarriesWhatItWasGiven(): void
    {
        $result = new RunResult(false, ['no'], true);

        $this->assertFalse($result->success);
        $this->assertTrue($result->isError);
        $this->assertSame(['no'], $result->lines);
    }
}
