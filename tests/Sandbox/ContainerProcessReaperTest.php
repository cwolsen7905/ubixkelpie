<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Closure;
use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\Sandbox\ContainerProcessReaper;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\ContainerProcessReaper
 *
 * What must hold:
 * - Not PID 1, it refuses and kills nothing (kill(-1) would reach the user's other processes)
 * - As PID 1 it kills, then reads the process list: only itself and zombies left is clear
 * - A process that is still running after every attempt, or a process list it cannot read, is a refusal; so is a
 *   process whose stat cannot be read or is empty while its /proc directory is still there
 * - Without posix_kill it runs the kill program: exactly `<kill> -9 -1`, no shell, from /, with PATH only; exit 0 or 1
 *   (nothing left to signal) goes on to the check; one that fails otherwise, or no kill program, is a refusal
 *
 * The kill is injected (a closure, or a scripted runner for the kill program) and /proc is a fixture: nothing here
 * signals a real process, and the posix path is never taken.
 *
 * @coversDefaultClass \Kelpie\Sandbox\ContainerProcessReaper
 */
final class ContainerProcessReaperTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $proc = '';

    private int $kills = 0;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ContainerProcessReaper::class);
    }

    /**
     * Not PID 1: a refusal, and no kill (this test process is not PID 1 either, so the default is safe too)
     *
     * @return void
     */
    public function testRefusesWhenNotPidOne(): void
    {
        $reaper = new ContainerProcessReaper($this->createStub(Logger::class), new ScriptedProcessRunner(), $this->proc, 4242, $this->kill());

        $this->assertStringContainsString('not PID 1', $reaper->reap());
        $this->assertSame(0, $this->kills);

        // With the PID read from getmypid(), and the kill still the counting one: when the test runner is itself PID 1
        // (phpunit run directly in a container, as in CI) it would go on to kill, so that is what is checked there.
        // No real signal is sent either way.
        $this->process(1, 'php', 'S');
        $this->process((int) getmypid(), 'php', 'S');
        $own = new ContainerProcessReaper($this->createStub(Logger::class), new ScriptedProcessRunner(), $this->proc, 0, $this->kill());
        if (getmypid() === 1) {
            $this->assertSame('', $own->reap());
            $this->assertSame(1, $this->kills);
        } else {
            $this->assertStringContainsString('not PID 1', $own->reap());
            $this->assertSame(0, $this->kills);
        }
    }

    /**
     * Only itself and zombies left: clear, after one kill
     *
     * @return void
     */
    public function testClearWithOnlyItselfAndZombies(): void
    {
        $this->process(1, 'php', 'S');
        $this->process(57, 'sleep', 'Z');
        $this->process(58, 'odd) name', 'X');
        mkdir($this->proc . '/self');

        $this->assertSame('', $this->reaper()->reap());
        $this->assertSame(1, $this->kills);
    }

    /**
     * A process still running after every attempt is named, and the container is not clear
     *
     * @return void
     */
    public function testSurvivorIsARefusal(): void
    {
        $this->process(1, 'php', 'S');
        $this->process(57, 'sleep', 'S');
        $this->process(60, 'tricky) Z (name', 'R');

        $this->assertSame('processes survived the reset: 57, 60', $this->reaper()->reap());
        $this->assertGreaterThan(1, $this->kills);
    }

    /**
     * A process whose stat cannot be read or is empty, while its directory is still there, counts as a survivor
     *
     * @return void
     */
    public function testUnreadableStatIsASurvivor(): void
    {
        $this->process(1, 'php', 'S');
        mkdir($this->proc . '/70');
        mkdir($this->proc . '/71');
        file_put_contents($this->proc . '/71/stat', '');
        $this->process(72, 'hidden', 'S');
        chmod($this->proc . '/72/stat', 0000);

        $result = $this->reaper()->reap();
        chmod($this->proc . '/72/stat', 0600);

        // As root the unreadable one is read anyway, and its state (S) makes it a survivor all the same.
        $this->assertSame('processes survived the reset: 70, 71, 72', $result);
    }

    /**
     * A process list it cannot read is a refusal
     *
     * @return void
     */
    public function testUnreadableProcessList(): void
    {
        $reaper = new ContainerProcessReaper($this->createStub(Logger::class), new ScriptedProcessRunner(), $this->proc . '/missing', 1, $this->kill());

        $this->assertStringContainsString('cannot be read', $reaper->reap());
    }

    /**
     * Without posix_kill the kill program runs, as exactly `<kill> -9 -1`, and a clear /proc is clear
     *
     * @return void
     */
    public function testFallsBackToTheKillProgram(): void
    {
        $this->process(1, 'php', 'S');
        foreach ([0, 1] as $exit) {
            $runner = $this->createMock(ProcessRunner::class);
            $runner->expects($this->once())->method('run')->with([$this->killProgram(), '-9', '-1'], '/', ['PATH' => '/usr/local/bin:/usr/bin:/bin'], $this->greaterThan(0))
                ->willReturn(new ProcessResult($exit, '', $exit === 1 ? 'kill: can\'t kill pid -1: No such process' : ''));

            $reaper = new ContainerProcessReaper($this->createStub(Logger::class), $runner, $this->proc, 1, null, false, $this->killProgram());

            $this->assertSame('', $reaper->reap(), 'exit ' . $exit);
        }
    }

    /**
     * A kill program that fails, times out or cannot start is a refusal, and the check is not reached
     *
     * @return void
     */
    public function testFailingKillProgramIsARefusal(): void
    {
        foreach ([new ProcessResult(2, '', 'bad'), new ProcessResult(-1, '', 'Could not start the process.'), new ProcessResult(-1, '', '', true), new ProcessResult(127, '', 'not found')] as $result) {
            $runner = $this->createMock(ProcessRunner::class);
            $runner->expects($this->once())->method('run')->willReturn($result);

            $reaper = new ContainerProcessReaper($this->createStub(Logger::class), $runner, $this->proc . '/missing', 1, null, false, $this->killProgram());

            $this->assertSame('the kill program failed (exit ' . $result->exitCode . ')', $reaper->reap());
        }
    }

    /**
     * Neither posix_kill nor a kill program: a refusal, and nothing is run
     *
     * @return void
     */
    public function testNoWayToKillIsARefusal(): void
    {
        $runner = new ScriptedProcessRunner();
        $reaper = new ContainerProcessReaper($this->createStub(Logger::class), $runner, $this->proc, 1, null, false, $this->proc . '/no-kill-here');

        $this->assertSame('neither posix_kill nor a kill program is available', $reaper->reap());
        $this->assertSame([], $runner->calls());
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->proc  = GateFixtures::directory();
        $this->kills = 0;
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        GateFixtures::remove($this->proc);
        GateFixtures::remove($this->proc . '-kill');
        parent::tearDown();
    }

    /**
     * A reaper that is PID 1 over the fixture, with the counting kill
     *
     * @return ContainerProcessReaper
     */
    private function reaper(): ContainerProcessReaper
    {
        return new ContainerProcessReaper($this->createStub(Logger::class), new ScriptedProcessRunner(), $this->proc, 1, $this->kill());
    }

    /**
     * An executable file standing in for the kill program (the runner is scripted: it is never run)
     *
     * @return string
     */
    private function killProgram(): string
    {
        $path = $this->proc . '-kill';
        if (!is_file($path)) {
            file_put_contents($path, "#!/bin/sh\nexit 0\n");
            chmod($path, 0755);
        }

        return $path;
    }

    /**
     * A kill that only counts
     *
     * @return Closure
     */
    private function kill(): Closure
    {
        return function (): bool {
            $this->kills++;

            return true;
        };
    }

    /**
     * A fixture process: <proc>/<pid>/stat as Linux writes it
     *
     * @param int    $pid   The PID
     * @param string $name  The command name
     * @param string $state The state letter
     *
     * @return void
     */
    private function process(int $pid, string $name, string $state): void
    {
        mkdir($this->proc . '/' . $pid);
        file_put_contents($this->proc . '/' . $pid . '/stat', $pid . ' (' . $name . ') ' . $state . ' 1 1 1 0 -1');
    }
}
