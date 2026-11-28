<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\WritableMountCheck;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\WritableMountCheck
 *
 * What must hold:
 * - As PID 1, every read-write directory mount it can write to must be the work directory, a clean directory or inside
 *   one; one that is not is named
 * - A read-write root filesystem, an unreadable mount table and a line it cannot read are refusals
 * - Read-only mounts, /proc and /sys, file mounts and directories it cannot write to do not count
 * - Not PID 1, it checks nothing
 *
 * The mount table is a fixture over real temporary directories.
 *
 * @coversDefaultClass \Kelpie\Sandbox\WritableMountCheck
 */
final class WritableMountCheckTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $root = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(WritableMountCheck::class);
    }

    /**
     * Every writable mount covered (equal to, or inside, the work or a clean directory): nothing to say
     *
     * @return void
     */
    public function testCoveredMounts(): void
    {
        $this->table([
            $this->line('/', 'ro,relatime'),
            $this->line($this->dir('work'), 'rw,nosuid'),
            $this->line($this->dir('tmp'), 'rw'),
            $this->line($this->dir('tmp/inner'), 'rw'),
            $this->line('/proc', 'rw,nosuid'),
            $this->line('/sys/fs/cgroup', 'rw'),
            $this->line($this->dir('readonly'), 'ro'),
        ]);

        $this->assertSame('', $this->check()->uncovered($this->root . '/work', [$this->root . '/tmp/']));
    }

    /**
     * A writable directory mount that is not wiped is named, with a space in its path decoded
     *
     * @return void
     */
    public function testUncoveredMountIsNamed(): void
    {
        $this->table([
            $this->line('/', 'ro'),
            $this->line($this->dir('work'), 'rw'),
            $this->line($this->dir('logs'), 'rw'),
            $this->line(str_replace(' ', '\\040', $this->dir('web var')), 'rw,noexec'),
        ]);

        $this->assertSame(
            'writable mounts not wiped between runs (add them to KELPIE_GATE_CLEAN_DIRS): ' . $this->root . '/logs, ' . $this->root . '/web var',
            $this->check()->uncovered($this->root . '/work', []),
        );
    }

    /**
     * File mounts and directories it cannot write to do not count
     *
     * @return void
     */
    public function testFileMountsAndUnwritableDirectoriesDoNotCount(): void
    {
        file_put_contents($this->root . '/hosts', '127.0.0.1 localhost');
        $locked = $this->dir('locked');
        chmod($locked, 0500);
        $this->table([$this->line('/', 'ro'), $this->line($this->root . '/hosts', 'rw'), $this->line($locked, 'rw')]);

        $result = $this->check()->uncovered($this->root . '/work', []);
        chmod($locked, 0700);

        // Root can write to a 0500 directory, so there it is (rightly) named.
        $this->assertSame(posix_geteuid() === 0 ? 'writable mounts not wiped between runs (add them to KELPIE_GATE_CLEAN_DIRS): ' . $locked : '', $result);
    }

    /**
     * A read-write root, an unreadable table and a line it cannot read are refusals
     *
     * @return void
     */
    public function testRefusals(): void
    {
        $this->table([$this->line('/', 'rw,relatime')]);
        $this->assertStringContainsString('root filesystem is mounted read-write', $this->check()->uncovered($this->root . '/work', []));

        file_put_contents($this->root . '/mountinfo', "garbage\n");
        $this->assertSame('the mount table has a line it cannot read', $this->check()->uncovered($this->root . '/work', []));

        $missing = new WritableMountCheck($this->createStub(Logger::class), $this->root . '/missing', 1);
        $this->assertStringContainsString('cannot be read', $missing->uncovered($this->root . '/work', []));
    }

    /**
     * Not PID 1: nothing is checked, even with a table that would be refused
     *
     * @return void
     */
    public function testNotPidOneChecksNothing(): void
    {
        $this->table([$this->line('/', 'rw')]);

        $this->assertSame('', (new WritableMountCheck($this->createStub(Logger::class), $this->root . '/mountinfo', 4242))->uncovered('/work', []));
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = GateFixtures::directory();
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        GateFixtures::remove($this->root);
        parent::tearDown();
    }

    /**
     * The check, as PID 1 over the fixture table
     *
     * @return WritableMountCheck
     */
    private function check(): WritableMountCheck
    {
        return new WritableMountCheck($this->createStub(Logger::class), $this->root . '/mountinfo', 1);
    }

    /**
     * A directory under the fixture root, created
     *
     * @param string $name Its name
     *
     * @return string
     */
    private function dir(string $name): string
    {
        $path = $this->root . '/' . $name;
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }

        return $path;
    }

    /**
     * One mountinfo line
     *
     * @param string $mountPoint The mount point, already escaped
     * @param string $options    The mount options
     *
     * @return string
     */
    private function line(string $mountPoint, string $options): string
    {
        return '36 35 98:0 / ' . $mountPoint . ' ' . $options . ' master:1 - ext4 /dev/root rw';
    }

    /**
     * Write the fixture table
     *
     * @param string[] $lines The lines
     *
     * @return void
     */
    private function table(array $lines): void
    {
        file_put_contents($this->root . '/mountinfo', implode("\n", $lines) . "\n");
    }
}
