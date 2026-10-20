<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\Service\Review\RepoToolsService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\RepoToolsService
 *
 * What must hold:
 * - read numbers lines, pages with offset and limit, and cuts long output
 * - Nothing outside the checkout is readable: not by `..`, not by an absolute path, not through a symlink; `.git` is never opened
 * - grep takes a PCRE pattern, an optional path and file glob, skips vendor and binary files, and caps its matches; a bad pattern is an error text
 * - glob matches `**` across directories and `*` within one
 * - An unknown tool, and the definitions the model is shown, are exactly read, grep and glob
 *
 * @coversDefaultClass \Kelpie\Service\Review\RepoToolsService
 */
final class RepoToolsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(RepoToolsService::class);
    }

    /**
     * Read numbers the lines and pages
     *
     * @return void
     */
    public function testReadNumbersAndPages(): void
    {
        $repo = $this->repo();

        try {
            $tools = $this->tools();

            $this->assertSame("1: alpha\n2: beta\n3: gamma\n", $tools->run($repo->path(), 'read', ['path' => 'src/a.txt']));
            $this->assertSame("2: beta\n", $tools->run($repo->path(), 'read', ['limit' => 1, 'offset' => 2, 'path' => 'src/a.txt']));
            $this->assertStringContainsString('no lines at that offset', $tools->run($repo->path(), 'read', ['offset' => 50, 'path' => 'src/a.txt']));
            $this->assertStringContainsString('no such file', $tools->run($repo->path(), 'read', ['path' => 'src/missing.txt']));
        } finally {
            $repo->remove();
        }
    }

    /**
     * A long file is cut and says how to continue
     *
     * @return void
     */
    public function testReadCutsLongOutput(): void
    {
        $repo = new TempRepo(['long.txt' => implode("\n", array_fill(0, 3000, str_repeat('y', 200)))]);

        try {
            $out = $this->tools()->run($repo->path(), 'read', ['path' => 'long.txt']);
        } finally {
            $repo->remove();
        }

        $this->assertStringEndsWith("[cut: pass a larger offset to continue]\n", $out);
        $this->assertLessThan(41500, strlen($out));
    }

    /**
     * Paths that leave the checkout, or enter .git, are refused
     *
     * @return void
     */
    public function testNothingOutsideTheCheckoutIsReadable(): void
    {
        $outside = new TempRepo(['secret.txt' => 'TOP SECRET']);
        $repo    = $this->repo();
        $repo->link('escape', $outside->path());
        $repo->link('secret-link.txt', $outside->path() . '/secret.txt');

        try {
            $tools = $this->tools();

            foreach (['../' . basename($outside->path()) . '/secret.txt', $outside->path() . '/secret.txt', 'escape/secret.txt', 'secret-link.txt', '.git/config', 'src/../.git/config'] as $path) {
                $out = $tools->run($repo->path(), 'read', ['path' => $path]);
                $this->assertStringContainsString('no such file', $out, $path);
                $this->assertStringNotContainsString('TOP SECRET', $out);
            }
            $this->assertStringNotContainsString('TOP SECRET', $tools->run($repo->path(), 'grep', ['pattern' => 'SECRET']));
            $this->assertStringNotContainsString('secret', $tools->run($repo->path(), 'glob', ['pattern' => '**/*.txt']));
        } finally {
            $repo->remove();
            $outside->remove();
        }
    }

    /**
     * Grep finds lines with their numbers, honours path and include, and skips vendor and binary files
     *
     * @return void
     */
    public function testGrep(): void
    {
        $repo = $this->repo();

        try {
            $tools = $this->tools();

            $this->assertSame("src/a.txt:2: beta\nsrc/b.php:1: <?php echo 'beta';\n", $tools->run($repo->path(), 'grep', ['pattern' => 'bet']));
            $this->assertSame("src/b.php:1: <?php echo 'beta';\n", $tools->run($repo->path(), 'grep', ['include' => '*.php', 'pattern' => 'beta']));
            $this->assertSame("src/a.txt:2: beta\n", $tools->run($repo->path(), 'grep', ['path' => 'src/a.txt', 'pattern' => 'beta|nothing']));
            $this->assertSame('(no matches)', $tools->run($repo->path(), 'grep', ['pattern' => 'zzz']));
            $this->assertStringNotContainsString('vendor', $tools->run($repo->path(), 'grep', ['pattern' => 'vendored']));
            $this->assertStringNotContainsString('bin.dat', $tools->run($repo->path(), 'grep', ['pattern' => 'binary']));
        } finally {
            $repo->remove();
        }
    }

    /**
     * A bad pattern, an empty pattern and a missing path are error texts
     *
     * @return void
     */
    public function testGrepErrors(): void
    {
        $repo = $this->repo();

        try {
            $tools = $this->tools();

            $this->assertStringContainsString('not a valid regular expression', $tools->run($repo->path(), 'grep', ['pattern' => '(unclosed']));
            $this->assertStringContainsString('not a valid regular expression', $tools->run($repo->path(), 'grep', ['pattern' => '']));
            $this->assertStringContainsString('no such path', $tools->run($repo->path(), 'grep', ['path' => 'nope', 'pattern' => 'a']));
        } finally {
            $repo->remove();
        }
    }

    /**
     * Grep stops at its match cap
     *
     * @return void
     */
    public function testGrepCapsMatches(): void
    {
        $repo = new TempRepo(['many.txt' => implode("\n", array_fill(0, 300, 'hit'))]);

        try {
            $out = $this->tools()->run($repo->path(), 'grep', ['pattern' => 'hit']);
        } finally {
            $repo->remove();
        }

        $this->assertSame(100, substr_count($out, 'many.txt:'));
        $this->assertStringContainsString('[cut at 100 matches', $out);
    }

    /**
     * Glob crosses directories with ** and not with *
     *
     * @return void
     */
    public function testGlob(): void
    {
        $repo = $this->repo();

        try {
            $tools = $this->tools();

            $this->assertSame("src/b.php\n", $tools->run($repo->path(), 'glob', ['pattern' => '**/*.php']));
            $this->assertSame("src/a.txt\nsrc/b.php\n", $tools->run($repo->path(), 'glob', ['pattern' => 'src/*']));
            $this->assertSame("src/a.txt\nsrc/b.php\nsrc/deep/c.txt\n", $tools->run($repo->path(), 'glob', ['pattern' => 'src/**']));
            $this->assertSame("root.txt\nsrc/a.txt\nsrc/deep/c.txt\n", $tools->run($repo->path(), 'glob', ['pattern' => '**/*.txt']));
            $this->assertSame('(no files match)', $tools->run($repo->path(), 'glob', ['pattern' => '*.rs']));
            $this->assertStringContainsString('empty', $tools->run($repo->path(), 'glob', ['pattern' => '']));
        } finally {
            $repo->remove();
        }
    }

    /**
     * Only the three tools exist, and the model is shown those three
     *
     * @return void
     */
    public function testOnlyReadGrepAndGlobExist(): void
    {
        $repo = $this->repo();

        try {
            $out = $this->tools()->run($repo->path(), 'bash', ['command' => 'cat /etc/passwd']);
        } finally {
            $repo->remove();
        }

        $this->assertStringContainsString('unknown tool bash', $out);
        $names = [];
        foreach ($this->tools()->definitions() as $definition) {
            $function = $definition['function'];
            $this->assertIsArray($function);
            $names[] = $function['name'];
        }
        $this->assertSame(['read', 'grep', 'glob'], $names);
    }

    /**
     * An unreadable checkout is an error text
     *
     * @return void
     */
    public function testMissingCheckout(): void
    {
        $this->assertStringContainsString('not readable', $this->tools()->run('/nonexistent/kelpie-review', 'glob', ['pattern' => '*']));
    }

    /**
     * @return TempRepo
     */
    private function repo(): TempRepo
    {
        return new TempRepo([
            '.git/config'       => 'git secrets',
            'bin.dat'           => "binary\0content",
            'node_modules/x.js' => 'vendored',
            'root.txt'          => 'root',
            'src/a.txt'         => "alpha\nbeta\ngamma",
            'src/b.php'         => "<?php echo 'beta';",
            'src/deep/c.txt'    => 'deep',
            'vendor/lib.php'    => 'vendored beta',
        ]);
    }

    /**
     * @return RepoToolsService
     */
    private function tools(): RepoToolsService
    {
        return new RepoToolsService($this->createStub(Logger::class));
    }
}
