<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\ProcOpenProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\ProcOpenProcessRunner
 *
 * What must hold:
 * - It returns exit code and output
 * - The process sees only the environment it was given
 * - A process over its time limit is killed
 *
 * @coversDefaultClass \Kelpie\Sandbox\ProcOpenProcessRunner
 */
final class ProcOpenProcessRunnerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProcOpenProcessRunner::class);
    }

    /**
     * Output and exit code come back
     *
     * @return void
     */
    public function testExitCodeAndOutput(): void
    {
        $result = (new ProcOpenProcessRunner($this->createStub(Logger::class)))->run(['sh', '-c', 'echo out; echo err >&2; exit 3'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], 10);

        $this->assertSame(3, $result->exitCode);
        $this->assertSame("out\n", $result->stdout);
        $this->assertSame("err\n", $result->stderr);
    }

    /**
     * Nothing is inherited from the parent environment
     *
     * @return void
     */
    public function testEnvironmentIsExact(): void
    {
        putenv('KELPIE_TEST_SECRET=leak');
        $result = (new ProcOpenProcessRunner($this->createStub(Logger::class)))->run(['sh', '-c', 'echo "[$KELPIE_TEST_SECRET][$GIVEN]"'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin', 'GIVEN' => 'yes'], 10);
        putenv('KELPIE_TEST_SECRET');

        $this->assertSame("[][yes]\n", $result->stdout);
    }

    /**
     * A runaway process is killed
     *
     * @return void
     */
    public function testTimeoutKills(): void
    {
        $started = microtime(true);
        $result  = (new ProcOpenProcessRunner($this->createStub(Logger::class)))->run(['sleep', '30'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], 1);

        $this->assertTrue($result->timedOut);
        $this->assertLessThan(10, microtime(true) - $started);
    }
}
