<?php

declare(strict_types=1);

namespace Kelpie\Tests\Autofix;

use Kelpie\Autofix\EditScope;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Autofix\EditScope
 *
 * What must hold:
 * - The thread's file, files its text names that exist, files the MR changed and their companion tests are allowed
 * - Anything else is not, nor is a path that leaves the repository or names .git
 * - A companion test that does not exist yet may be created, and is listed as creatable
 *
 * @coversDefaultClass \Kelpie\Autofix\EditScope
 */
final class EditScopeTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $dir = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(EditScope::class);
    }

    /**
     * Thread file, named files, changed files and their tests are in; the rest is out
     *
     * @return void
     */
    public function testAllowedSet(): void
    {
        $scope = (new EditScope($this->createStub(Logger::class)))->forThread($this->dir, 'php/Kelpie/A.php', 'See `php/Kelpie/B.php` and nothing/missing.php, also ../etc/passwd', ['php/Kelpie/C.php']);

        foreach (['php/Kelpie/A.php', 'php/Kelpie/B.php', 'php/Kelpie/C.php', 'tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php', './php/Kelpie/A.php'] as $path) {
            $this->assertTrue($scope->allows($path), $path);
        }
        foreach (['php/Kelpie/D.php', 'nothing/missing.php', 'tests/DTest.php', '../x.php', '/etc/passwd', '.git/config', 'composer.json'] as $path) {
            $this->assertFalse($scope->allows($path), $path);
        }
    }

    /**
     * Existing files come thread-file first; the others that may be made are creatable
     *
     * @return void
     */
    public function testExistingAndCreatable(): void
    {
        $scope = (new EditScope($this->createStub(Logger::class)))->forThread($this->dir, 'php/Kelpie/B.php', '', ['php/Kelpie/A.php']);

        $this->assertSame(['php/Kelpie/B.php', 'php/Kelpie/A.php', 'tests/ATest.php'], $scope->existing());
        $this->assertSame(['tests/BTest.php'], $scope->creatable());
    }

    /**
     * Companion paths follow the repo's convention and the common ones elsewhere
     *
     * @return void
     */
    public function testCompanions(): void
    {
        $this->assertSame(['tests/Service/XTest.php'], (new EditScope($this->createStub(Logger::class)))->companions('php/Kelpie/Service/X.php'));
        $this->assertSame(['php/Kelpie/Service/X.php'], (new EditScope($this->createStub(Logger::class)))->companions('tests/Service/XTest.php'));
        $this->assertSame(['pkg/brief_test.go'], (new EditScope($this->createStub(Logger::class)))->companions('pkg/brief.go'));
        $this->assertSame(['pkg/brief.go'], (new EditScope($this->createStub(Logger::class)))->companions('pkg/brief_test.go'));
        $this->assertSame(['src/a.test.ts'], (new EditScope($this->createStub(Logger::class)))->companions('src/a.ts'));
        $this->assertSame(['pkg/test_a.py'], (new EditScope($this->createStub(Logger::class)))->companions('pkg/a.py'));
        $this->assertSame(['pkg/a.py'], (new EditScope($this->createStub(Logger::class)))->companions('pkg/test_a.py'));
        $this->assertContains('src/a.spec.ts', (new EditScope($this->createStub(Logger::class)))->companions('src/a.ts', (string) realpath($this->dir)));
        $this->assertSame([], (new EditScope($this->createStub(Logger::class)))->companions('../x.php'));
        $this->assertSame([], (new EditScope($this->createStub(Logger::class)))->companions('Makefile'));
    }

    /**
     * Fixture checkout with php/Kelpie/A.php, B.php and tests/ATest.php
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kelpie-scope-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/php/Kelpie', 0700, true);
        mkdir($this->dir . '/tests', 0700, true);
        file_put_contents($this->dir . '/php/Kelpie/A.php', "<?php\n");
        file_put_contents($this->dir . '/php/Kelpie/B.php', "<?php\n");
        file_put_contents($this->dir . '/tests/ATest.php', "<?php\n");
        mkdir($this->dir . '/src', 0700, true);
        file_put_contents($this->dir . '/src/a.spec.ts', "x\n");
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->dir, 'kelpie-scope-test-')) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }
}
