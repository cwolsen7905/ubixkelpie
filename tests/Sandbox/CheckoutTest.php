<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\Checkout
 *
 * What must hold:
 * - It checks out the branch at the given sha, detached
 * - It measures the real diff and flags deletes and skipped tests
 * - It pushes without force: a branch that moved is rejected, never overwritten
 * - It refuses branch names that could be read as options or refspecs
 * - The credential script does not outlive the call, and close() removes the checkout
 *
 * @coversDefaultClass \Kelpie\Sandbox\Checkout
 */
final class CheckoutTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $base = '';

    private string $remote = '';

    private string $sha = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(Checkout::class);
    }

    /**
     * Opens detached at the sha, even when the branch has moved on
     *
     * @return void
     */
    public function testOpensAtShaNotBranchHead(): void
    {
        $this->git($this->base . '/work', ['commit', '--allow-empty', '-m', 'later']);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/feat/x']);

        $checkout = $this->checkout();
        $this->assertTrue($checkout->open($this->remote, 'feat/x', $this->sha));
        $this->assertSame($this->sha, trim($this->git($checkout->directory(), ['rev-parse', 'HEAD'])));
        $checkout->close();
    }

    /**
     * An unknown sha does not open
     *
     * @return void
     */
    public function testUnknownShaFails(): void
    {
        $checkout = $this->checkout();
        $this->assertFalse($checkout->open($this->remote, 'feat/x', str_repeat('b', 40)));
        $checkout->close();
    }

    /**
     * The diff is measured from git
     *
     * @return void
     */
    public function testMeasuresChanges(): void
    {
        $checkout = $this->checkout();
        $checkout->open($this->remote, 'feat/x', $this->sha);
        $dir = $checkout->directory();
        file_put_contents($dir . '/a.txt', "one\ntwo\n");
        file_put_contents($dir . '/new.txt', "x\n");

        $changes = $checkout->stageAndMeasure();

        $this->assertNotNull($changes);
        $this->assertSame(['a.txt', 'new.txt'], $changes->paths);
        $this->assertSame(2, $changes->changedLines);
        $this->assertFalse($changes->deletesOrRenamesFiles);
        $this->assertFalse($changes->weakensTests);
        $checkout->close();
    }

    /**
     * The staged diff is what git writes, and it applies to the sha the checkout was made at
     *
     * @return void
     */
    public function testStagedDiffAppliesToTheSha(): void
    {
        $checkout = $this->checkout();
        $checkout->open($this->remote, 'feat/x', $this->sha);
        $dir = $checkout->directory();
        file_put_contents($dir . '/a.txt', "one\ntwo\n");
        $checkout->stageAndMeasure();

        $diff = $checkout->stagedDiff();

        $this->assertNotNull($diff);
        $this->assertStringContainsString('diff --git a/a.txt b/a.txt', $diff);
        $this->assertStringContainsString('+two', $diff);
        $patch = $this->base . '/staged.patch';
        file_put_contents($patch, $diff);
        $this->git($this->base . '/work', ['checkout', '--detach', $this->sha]);
        $this->assertSame('', $this->git($this->base . '/work', ['apply', '--check', $patch]));
        $checkout->close();
    }

    /**
     * Deleted files and skipped tests are flagged
     *
     * @return void
     */
    public function testFlagsDeletesAndWeakenedTests(): void
    {
        $checkout = $this->checkout();
        $checkout->open($this->remote, 'feat/x', $this->sha);
        $dir = $checkout->directory();
        unlink($dir . '/a.txt');
        file_put_contents($dir . '/b.php', "<?php \$this->markTestSkipped('later');\n");

        $changes = $checkout->stageAndMeasure();

        $this->assertNotNull($changes);
        $this->assertTrue($changes->deletesOrRenamesFiles);
        $this->assertTrue($changes->weakensTests);
        $checkout->close();
    }

    /**
     * A fast-forward push lands, and nothing else moves
     *
     * @return void
     */
    public function testPushesFastForward(): void
    {
        $checkout = $this->checkout();
        $checkout->open($this->remote, 'feat/x', $this->sha);
        file_put_contents($checkout->directory() . '/a.txt', "fixed\n");
        $checkout->stageAndMeasure();
        $commit = $checkout->commit("fix\n\nKelpie-Autofix: thread=d1 sha=" . $this->sha);

        $this->assertNotNull($commit);
        $this->assertTrue($checkout->push('feat/x'));
        $this->assertSame($commit, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', $commit . '^'])));
        $this->assertSame($this->sha, trim($this->git($this->remote, ['rev-parse', 'refs/heads/dev'])));
        $checkout->close();
    }

    /**
     * A branch that moved is rejected and keeps the other person's commit
     *
     * @return void
     */
    public function testPushIsRejectedWhenBranchMoved(): void
    {
        $checkout = $this->checkout();
        $checkout->open($this->remote, 'feat/x', $this->sha);

        $this->git($this->base . '/work', ['commit', '--allow-empty', '-m', 'someone else']);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/feat/x']);
        $theirs = trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x']));

        file_put_contents($checkout->directory() . '/a.txt', "fixed\n");
        $checkout->stageAndMeasure();
        $checkout->commit('fix');

        $this->assertFalse($checkout->push('feat/x'));
        $this->assertSame($theirs, trim($this->git($this->remote, ['rev-parse', 'refs/heads/feat/x'])));
        $checkout->close();
    }

    /**
     * Names that could be options or forced refspecs are refused
     *
     * @return void
     */
    public function testRefusesUnsafeRefs(): void
    {
        $checkout = $this->checkout();

        foreach (['--upload-pack=touch /tmp/x', '-f', '+feat/x', 'a..b', 'feat/x/', 'x.lock', ''] as $branch) {
            $this->assertFalse($checkout->open($this->remote, $branch, $this->sha), $branch);
            $this->assertFalse($checkout->push($branch), $branch);
        }
        $this->assertFalse($checkout->open($this->remote, 'feat/x', 'HEAD'));
    }

    /**
     * No git call is ever given a force flag or a plus refspec, and the token is in no argv or env
     *
     * @return void
     */
    public function testPushArgvHasNoForceAndNoToken(): void
    {
        $recorder = new ScriptedProcessRunner();
        $checkout = new Checkout($this->createStub(Logger::class), $recorder, 'glpat-secret');
        $checkout->open($this->remote, 'feat/x', $this->sha);
        $checkout->push('feat/x');

        $pushes = array_values(array_filter($recorder->calls(), static function (array $call): bool {
            return in_array('push', $call['argv'], true);
        }));
        $this->assertCount(1, $pushes);
        foreach ($recorder->calls() as $call) {
            foreach ($call['argv'] as $arg) {
                $this->assertStringNotContainsString('force', $arg);
                $this->assertStringStartsNotWith('+', $arg);
                $this->assertStringNotContainsString('glpat-secret', $arg);
            }
            $this->assertStringNotContainsString('glpat-secret', implode(' ', $call['env']));
        }
        $argv = $pushes[0]['argv'];
        $this->assertSame('HEAD:refs/heads/feat/x', array_pop($argv));
        $checkout->close();
    }

    /**
     * The credential script is gone after a network call and the directory after close()
     *
     * @return void
     */
    public function testCleansUp(): void
    {
        $checkout = $this->checkout();
        $checkout->open($this->remote, 'feat/x', $this->sha);
        $root = dirname($checkout->directory());

        $this->assertFileDoesNotExist($root . '/askpass.sh');
        $this->assertDirectoryExists($root);

        $checkout->close();
        $this->assertDirectoryDoesNotExist($root);
    }

    /**
     * A bare remote with `feat/x` and `dev` at one commit that holds a.txt
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->base   = sys_get_temp_dir() . '/kelpie-checkout-test-' . bin2hex(random_bytes(6));
        $this->remote = $this->base . '/remote.git';
        mkdir($this->base . '/work', 0700, true);
        $this->git($this->base, ['init', '--bare', '-b', 'dev', $this->remote]);
        $this->git($this->base . '/work', ['init', '-b', 'dev']);
        $this->git($this->base . '/work', ['config', 'user.name', 't']);
        $this->git($this->base . '/work', ['config', 'user.email', 't@example.com']);
        file_put_contents($this->base . '/work/a.txt', "one\n");
        $this->git($this->base . '/work', ['add', '.']);
        $this->git($this->base . '/work', ['commit', '-m', 'init']);
        $this->git($this->base . '/work', ['remote', 'add', 'origin', $this->remote]);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/dev', 'HEAD:refs/heads/feat/x']);
        $this->sha = trim($this->git($this->base . '/work', ['rev-parse', 'HEAD']));
    }

    /**
     * Remove the scratch repos
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->base, 'kelpie-checkout-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * @return Checkout
     */
    private function checkout(): Checkout
    {
        $logger = $this->createStub(Logger::class);

        return new Checkout($logger, new ProcOpenProcessRunner($logger), 'tok-not-real');
    }

    /**
     * Run git for the fixture
     *
     * @param string   $cwd  Where
     * @param string[] $args The arguments
     *
     * @return string stdout
     */
    private function git(string $cwd, array $args): string
    {
        $out = [];
        exec('cd ' . escapeshellarg($cwd) . ' && git ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);

        return implode("\n", $out);
    }
}
