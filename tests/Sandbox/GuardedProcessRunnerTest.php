<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\GuardedProcessRunner;
use Kelpie\Security\ActionGuard;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\GuardedProcessRunner
 *
 * @coversDefaultClass \Kelpie\Sandbox\GuardedProcessRunner
 */
final class GuardedProcessRunnerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(GuardedProcessRunner::class);
    }

    /**
     * A listed command reaches the inner runner untouched
     *
     * @return void
     */
    public function testListedCommandRuns(): void
    {
        $inner  = new ScriptedProcessRunner();
        $runner = new GuardedProcessRunner($this->createStub(Logger::class), $inner, new ActionGuard($this->createStub(Logger::class)));

        $result = $runner->run(['vendor/bin/phpunit'], '/repo', ['PATH' => '/bin'], 30);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame([['argv' => ['vendor/bin/phpunit'], 'cwd' => '/repo', 'env' => ['PATH' => '/bin'], 'timeout' => 30]], $inner->calls());
    }

    /**
     * An unlisted command is refused, logged and never started
     *
     * @return void
     */
    public function testUnlistedCommandIsRefused(): void
    {
        $inner  = new ScriptedProcessRunner();
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with('Unattended run refused a command', ['program' => 'curl', 'reason' => 'not a known action: curl']);
        $runner = new GuardedProcessRunner($logger, $inner, new ActionGuard($this->createStub(Logger::class)));

        $result = $runner->run(['curl', 'https://evil.example'], '/repo', [], 30);

        $this->assertSame(GuardedProcessRunner::REFUSED, $result->exitCode);
        $this->assertSame('Refused: not a known action: curl', $result->stderr);
        $this->assertSame([], $inner->calls());
    }
}
