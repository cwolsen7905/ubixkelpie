<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Closure;
use Kelpie\Sandbox\ContainerProcessReaper;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\ContainerProcessReaper
 *
 * What must hold:
 * - Not PID 1, it refuses and kills nothing (kill(-1) would reach the user's other processes)
 * - As PID 1 it kills, then reads the process list: only itself and zombies left is clear
 * - A process that is still running after every attempt, or a process list it cannot read, is a refusal
 *
 * The kill is injected and /proc is a fixture: nothing here signals a real process.
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
        $reaper = new ContainerProcessReaper($this->createStub(Logger::class), $this->proc, 4242, $this->kill());

        $this->assertStringContainsString('not PID 1', $reaper->reap());
        $this->assertSame(0, $this->kills);
        $this->assertStringContainsString('not PID 1', (new ContainerProcessReaper($this->createStub(Logger::class)))->reap());
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
        mkdir($this->proc . '/99');

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
     * A process list it cannot read is a refusal
     *
     * @return void
     */
    public function testUnreadableProcessList(): void
    {
        $reaper = new ContainerProcessReaper($this->createStub(Logger::class), $this->proc . '/missing', 1, $this->kill());

        $this->assertStringContainsString('cannot be read', $reaper->reap());
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
        parent::tearDown();
    }

    /**
     * A reaper that is PID 1 over the fixture, with the counting kill
     *
     * @return ContainerProcessReaper
     */
    private function reaper(): ContainerProcessReaper
    {
        return new ContainerProcessReaper($this->createStub(Logger::class), $this->proc, 1, $this->kill());
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
