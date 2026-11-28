<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\TarArchive;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\TarArchive
 *
 * What must hold:
 * - A checkout packs and unpacks to the same files, links and execute bits, long paths included; skipped names stay out
 * - Unpacking refuses an absolute path, a `..` component, a link leading out (directly or through another link), a
 *   hard link or device, more bytes or entries than allowed, a damaged header, and a target that is not empty
 * - Nothing is written outside the target when it refuses
 *
 * @coversDefaultClass \Kelpie\Sandbox\TarArchive
 */
final class TarArchiveTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $scratch = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TarArchive::class);
    }

    /**
     * Packing and unpacking keeps files, directories, links, execute bits and long paths, and leaves out what is skipped
     *
     * @return void
     */
    public function testRoundTrip(): void
    {
        $source = $this->scratch . '/source';
        $long   = str_repeat('d', 80) . '/' . str_repeat('e', 80);
        mkdir($source . '/' . $long, 0755, true);
        mkdir($source . '/.git');
        file_put_contents($source . '/.git/HEAD', 'ref');
        file_put_contents($source . '/plain.txt', "hello\n");
        file_put_contents($source . '/run.sh', "#!/bin/sh\n");
        chmod($source . '/run.sh', 0755);
        file_put_contents($source . '/' . $long . '/' . str_repeat('f', 90) . '.txt', 'deep');
        file_put_contents($source . '/empty', '');
        symlink('plain.txt', $source . '/link');

        $archive = new TarArchive($this->createStub(Logger::class));
        $this->assertSame('', $archive->pack($source, $this->scratch . '/a.tgz', ['.git']));
        mkdir($this->scratch . '/out');
        $this->assertSame('', $archive->unpack($this->scratch . '/a.tgz', $this->scratch . '/out', 1048576, 100));

        $out = $this->scratch . '/out';
        $this->assertSame("hello\n", file_get_contents($out . '/plain.txt'));
        $this->assertSame('deep', file_get_contents($out . '/' . $long . '/' . str_repeat('f', 90) . '.txt'));
        $this->assertSame('', file_get_contents($out . '/empty'));
        $this->assertSame('plain.txt', readlink($out . '/link'));
        $this->assertSame(0755, fileperms($out . '/run.sh') & 0777);
        $this->assertSame(0644, fileperms($out . '/plain.txt') & 0777);
        $this->assertFileDoesNotExist($out . '/.git');
    }

    /**
     * Entries that would land outside the target are refused, and nothing is written outside it
     *
     * @return void
     */
    public function testPathsOutOfTheTargetAreRefused(): void
    {
        foreach (
            [
                'absolute'     => [GateFixtures::entry('/' . ltrim($this->scratch, '/') . '/escaped', '0', 'x')],
                'deep dot dot' => [GateFixtures::entry('a/../../escaped', '0', 'x')],
                'dot dot'      => [GateFixtures::entry('../escaped', '0', 'x')],
                'dot dot dir'  => [GateFixtures::entry('a/../../escaped/', '5')],
                'nul'          => [GateFixtures::entry("a\0/../../escaped", '0', 'x')],
            ] as $case => $entries
        ) {
            $this->assertStringContainsString('leaves the work directory', $this->unpackEntries($entries), $case);
            $this->assertFileDoesNotExist($this->scratch . '/escaped', $case);
        }
    }

    /**
     * A link that leads out is refused: absolute, by `..`, or through a link to the target itself
     *
     * @return void
     */
    public function testLinksOutOfTheTargetAreRefused(): void
    {
        foreach (
            [
                'absolute target' => [GateFixtures::entry('l', '2', '', '/etc/passwd')],
                'deep dot dot'    => [GateFixtures::entry('a/l', '2', '', '../../escaped')],
                'dot dot target'  => [GateFixtures::entry('l', '2', '', '../escaped')],
                'through a link'  => [GateFixtures::entry('self', '2', '', '.'), GateFixtures::entry('self/up', '2', '', '..')],
                'up a link to .'  => [GateFixtures::entry('self', '2', '', '.'), GateFixtures::entry('up', '2', '', 'self/..')],
            ] as $case => $entries
        ) {
            $this->assertNotSame('', $this->unpackEntries($entries), $case);
        }
    }

    /**
     * A file is never written through a link: links are created after the files, so a later file entry cannot follow one
     *
     * @return void
     */
    public function testFilesAreNotWrittenThroughLinks(): void
    {
        mkdir($this->scratch . '/elsewhere');
        $error = $this->unpackEntries([
            GateFixtures::entry('l', '2', '', 'sub'),
            GateFixtures::entry('l/x', '0', 'x'),
        ]);

        $this->assertNotSame('', $error);
        $this->assertFileDoesNotExist($this->scratch . '/elsewhere/x');
    }

    /**
     * Hard links, devices, FIFOs and unknown types are refused
     *
     * @return void
     */
    public function testOtherEntryTypesAreRefused(): void
    {
        foreach (['1' => 'hard link', '3' => 'character device', '4' => 'block device', '6' => 'fifo', 'g' => 'global header', 'K' => 'GNU long link'] as $type => $case) {
            $this->assertStringContainsString('not a file, directory or link', $this->unpackEntries([GateFixtures::entry('x', (string) $type, '', 'plain.txt')]), $case);
        }
    }

    /**
     * Size and entry caps hold, and a damaged or truncated archive is refused
     *
     * @return void
     */
    public function testCapsAndDamage(): void
    {
        $archive = new TarArchive($this->createStub(Logger::class));

        $this->assertStringContainsString('more than 10 bytes', $this->unpackEntries([GateFixtures::entry('big', '0', str_repeat('x', 11))]));
        $this->assertStringContainsString('more than 100 entries', $this->unpackEntries(array_fill(0, 101, GateFixtures::entry('same', '0', ''))));

        $damaged = GateFixtures::entry('x', '0', 'x');
        $damaged = substr_replace($damaged, 'Y', 0, 1);
        $this->assertSame('the archive has a damaged header', $this->unpackEntries([$damaged]));

        $path = $this->scratch . '/short.tgz';
        file_put_contents($path, (string) gzencode(GateFixtures::entry('x', '0', str_repeat('x', 600))));
        mkdir($this->scratch . '/short');
        $this->assertSame('the archive ends early', $archive->unpack($path, $this->scratch . '/short', 1048576, 100));

        file_put_contents($path, 'not gzip at all');
        $this->assertNotSame('', $archive->unpack($path, $this->scratch . '/short', 1048576, 100));
    }

    /**
     * The target must exist and be empty
     *
     * @return void
     */
    public function testTargetMustBeEmpty(): void
    {
        $archive = new TarArchive($this->createStub(Logger::class));
        $path    = GateFixtures::archive($this->scratch . '/a.tgz', [GateFixtures::entry('x', '0', 'x')]);
        mkdir($this->scratch . '/full');
        file_put_contents($this->scratch . '/full/left-over', 'x');

        $this->assertSame('the target is not an empty directory', $archive->unpack($path, $this->scratch . '/full', 1048576, 100));
        $this->assertSame('the target is not an empty directory', $archive->unpack($path, $this->scratch . '/missing', 1048576, 100));
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = GateFixtures::directory();
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        GateFixtures::remove($this->scratch);
        parent::tearDown();
    }

    /**
     * Unpack hand-built entries into a fresh `<scratch>/target-N`, with caps of 10 bytes and 100 entries
     *
     * @param string[] $entries The raw entries
     *
     * @return string The refusal, empty when it unpacked
     */
    private function unpackEntries(array $entries): string
    {
        $target = $this->scratch . '/target-' . bin2hex(random_bytes(4));
        mkdir($target);
        $path = GateFixtures::archive($target . '.tgz', $entries);

        return (new TarArchive($this->createStub(Logger::class)))->unpack($path, $target, 10, 100);
    }
}
