<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\Service\Autofix\EditApplyService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\EditApplyService
 *
 * What must hold:
 * - An edit applies only when its old text occurs exactly once; edits apply in order
 * - If one edit is refused nothing is written
 * - A path outside the checkout, under .git, a symlink out or not a file is refused
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\EditApplyService
 */
final class EditApplyServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $dir = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(EditApplyService::class);
    }

    /**
     * Edits apply in order, each to the text the one before left
     *
     * @return void
     */
    public function testEditsApplyInOrder(): void
    {
        $result = $this->service()->apply($this->dir, [new ProposedEdit('a.txt', 'alpha', 'ALPHA'), new ProposedEdit('a.txt', 'ALPHA beta', 'gamma')]);

        $this->assertNull($result);
        $this->assertSame("gamma\ndup\ndup\n", file_get_contents($this->dir . '/a.txt'));
    }

    /**
     * Old text found twice or not at all is refused, and nothing is written for any edit
     *
     * @return void
     */
    public function testRefusalWritesNothing(): void
    {
        $edits = [new ProposedEdit('a.txt', 'alpha', 'ALPHA'), new ProposedEdit('a.txt', 'dup', 'x')];

        $this->assertSame('Edit 2: the old text occurs 2 times in `a.txt`, not once.', $this->service()->apply($this->dir, $edits));
        $this->assertSame("alpha beta\ndup\ndup\n", file_get_contents($this->dir . '/a.txt'));
        $this->assertSame('Edit 1: the old text occurs 0 times in `a.txt`, not once.', $this->service()->apply($this->dir, [new ProposedEdit('a.txt', 'zeta', 'x')]));
    }

    /**
     * An edit that changes nothing, or has empty old text, is refused
     *
     * @return void
     */
    public function testNoOpAndEmptyOldAreRefused(): void
    {
        $this->assertSame('Edit 1 changes nothing.', $this->service()->apply($this->dir, [new ProposedEdit('a.txt', 'alpha', 'alpha')]));
        $this->assertSame('Edit 1 changes nothing.', $this->service()->apply($this->dir, [new ProposedEdit('a.txt', '', 'x')]));
    }

    /**
     * Nothing to apply, or too many edits, is refused
     *
     * @return void
     */
    public function testEmptyAndOversizedListsAreRefused(): void
    {
        $this->assertSame('There is nothing to apply.', $this->service()->apply($this->dir, []));

        $many = array_fill(0, EditApplyService::MAX_EDITS + 1, new ProposedEdit('a.txt', 'alpha', 'x'));
        $this->assertSame('More than ' . EditApplyService::MAX_EDITS . ' edits.', $this->service()->apply($this->dir, $many));
    }

    /**
     * Paths that leave the checkout, name .git, are not files or are links out are refused
     *
     * @return void
     */
    public function testUnsafePathsAreRefused(): void
    {
        foreach (['../outside.txt', '/etc/passwd', 'sub/../../outside.txt', '.git/config', 'sub', 'missing.txt', 'link.txt', 'a\\b', ''] as $path) {
            $result = $this->service()->apply($this->dir, [new ProposedEdit($path, 'secret', 'x')]);

            $this->assertNotNull($result, $path);
            $this->assertStringContainsString('not a plain file in the repository', $result, $path);
        }
        $this->assertSame("secret\n", file_get_contents(dirname($this->dir) . '/outside.txt'));
    }

    /**
     * A file in a subdirectory is fine
     *
     * @return void
     */
    public function testSubdirectoryFileApplies(): void
    {
        $this->assertNull($this->service()->apply($this->dir, [new ProposedEdit('sub/b.txt', 'inner', 'INNER')]));
        $this->assertSame("INNER\n", file_get_contents($this->dir . '/sub/b.txt'));
    }

    /**
     * A checkout with a file, a subdirectory, .git and a link out of the checkout
     *
     * @return void
     */
    protected function setUp(): void
    {
        $base      = sys_get_temp_dir() . '/kelpie-edit-test-' . bin2hex(random_bytes(6));
        $this->dir = $base . '/repo';
        mkdir($this->dir . '/sub', 0700, true);
        mkdir($this->dir . '/.git', 0700, true);
        file_put_contents($this->dir . '/a.txt', "alpha beta\ndup\ndup\n");
        file_put_contents($this->dir . '/sub/b.txt', "inner\n");
        file_put_contents($this->dir . '/.git/config', "secret\n");
        file_put_contents($base . '/outside.txt', "secret\n");
        symlink($base . '/outside.txt', $this->dir . '/link.txt');
    }

    /**
     * Remove the scratch checkout
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $base = dirname($this->dir);
        if (str_contains($base, 'kelpie-edit-test-')) {
            exec('rm -rf ' . escapeshellarg($base));
        }
    }

    /**
     * @return EditApplyService
     */
    private function service(): EditApplyService
    {
        return new EditApplyService($this->createStub(Logger::class));
    }
}
