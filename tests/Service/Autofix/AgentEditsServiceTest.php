<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Closure;
use DateTimeImmutable;
use Kelpie\DataTransferObject\ProposedEdits;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Service\Autofix\AgentEditsService;
use Kelpie\Service\Autofix\EditApplyService;
use Kelpie\Service\Autofix\StoredEditsService;
use Kelpie\Tests\Agent\ScriptedFixerAgent;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\AgentEditsService
 *
 * What must hold:
 * - The agent's change, in any file and in new files, comes back as exact-text edits measured from git
 * - Those edits, stored and read back, replay to the same files in a fresh checkout at the head
 * - No change, a deleted file, too many files, an empty or non-UTF-8 file or an agent that did not finish is a failure with the reason
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\AgentEditsService
 */
final class AgentEditsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $base = '';

    private string $remote = '';

    private string $sha = '';

    private Checkout $checkout;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AgentEditsService::class);
    }

    /**
     * A change in another file and a new test file become edits that replay at the head
     *
     * @return void
     */
    public function testChangeInOtherFilesAndANewTestReplays(): void
    {
        $edits = $this->edits(static function (string $dir): string {
            file_put_contents($dir . '/pkg/brief.go', "package pkg\n\n// Tools: push, gate\n");
            file_put_contents($dir . '/pkg/brief_test.go', "package pkg\n");

            return file_exists($dir . '/.git') ? 'The agent could see .git.' : "Added gate to the brief\nand a test.";
        });

        $this->assertSame('', $edits->failure);
        $this->assertSame('Added gate to the brief and a test.', $edits->summary);
        $this->assertSame(0.02, $edits->completion?->costUsd);
        $this->assertCount(2, $edits->edits);
        $this->assertSame('pkg/brief.go', $edits->edits[0]->file);
        $this->assertSame('pkg/brief_test.go', $edits->edits[1]->file);
        $this->assertSame('', $edits->edits[1]->old);
        $this->assertTrue(is_file($this->checkout->directory() . '/.git'));

        $logger = $this->createStub(Logger::class);
        $stored = (new StoredEditsService($logger, new JsonService($logger)))->decode($edits->editsJson);
        $fresh  = $this->checkout();
        $fresh->open($this->remote, 'feat/x', $this->sha);
        $this->assertNull((new EditApplyService($logger))->apply($fresh->directory(), $stored));
        $this->assertSame("package pkg\n\n// Tools: push, gate\n", file_get_contents($fresh->directory() . '/pkg/brief.go'));
        $this->assertSame("package pkg\n", file_get_contents($fresh->directory() . '/pkg/brief_test.go'));
        $fresh->close();
    }

    /**
     * No change is a failure carrying the agent's reason and its cost
     *
     * @return void
     */
    public function testNoChangeIsAFailureWithTheReason(): void
    {
        $edits = $this->edits(static function (): string {
            return 'It needs a design call.';
        });

        $this->assertSame([], $edits->edits);
        $this->assertSame('The fixing agent made no change: It needs a design call.', $edits->failure);
        $this->assertNotNull($edits->completion);
    }

    /**
     * A deleted file and too many files are refused; an agent that did not finish is a failure with no cost
     *
     * @return void
     */
    public function testDeletesTooManyFilesAndUnfinishedRunsAreRefused(): void
    {
        $deleted = $this->edits(static function (string $dir): string {
            unlink($dir . '/pkg/brief.go');

            return 'Removed it.';
        });
        $this->assertSame('The fixing agent deleted or renamed a file.', $deleted->failure);
        $this->checkout->close();

        $many = $this->edits(static function (string $dir): string {
            for ($i = 0; $i <= EditApplyService::MAX_EDITS; $i++) {
                file_put_contents($dir . '/f' . $i . '.txt', "x\n");
            }

            return 'Many.';
        });
        $this->assertStringContainsString('changed 6 files (limit 5)', $many->failure);
        $this->checkout->close();

        $empty = $this->edits(static function (string $dir): string {
            file_put_contents($dir . '/pkg/brief.go', '');

            return 'Emptied it.';
        });
        $this->assertStringContainsString('which a proposal cannot replay', $empty->failure);
        $this->checkout->close();

        $binary = $this->edits(static function (string $dir): string {
            file_put_contents($dir . '/pkg/blob.bin', "\xff\xfe\x00bin");

            return 'Added a blob.';
        });
        $this->assertStringContainsString('not UTF-8 text', $binary->failure);
        $this->checkout->close();

        $unfinished = $this->edits(static function (): ?string {
            return null;
        });
        $this->assertSame('The fixing agent did not finish.', $unfinished->failure);
        $this->assertNull($unfinished->completion);
    }

    /**
     * A remote with `feat/x` at one commit holding pkg/brief.go
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->base   = sys_get_temp_dir() . '/kelpie-agent-edits-test-' . bin2hex(random_bytes(6));
        $this->remote = $this->base . '/remote.git';
        mkdir($this->base . '/work/pkg', 0700, true);
        $this->git($this->base, ['init', '--bare', '-b', 'dev', $this->remote]);
        $this->git($this->base . '/work', ['init', '-b', 'dev']);
        $this->git($this->base . '/work', ['config', 'user.name', 't']);
        $this->git($this->base . '/work', ['config', 'user.email', 't@example.com']);
        file_put_contents($this->base . '/work/pkg/brief.go', "package pkg\n\n// Tools: push\n");
        $this->git($this->base . '/work', ['add', '.']);
        $this->git($this->base . '/work', ['commit', '-m', 'init']);
        $this->git($this->base . '/work', ['remote', 'add', 'origin', $this->remote]);
        $this->git($this->base . '/work', ['push', 'origin', 'HEAD:refs/heads/dev', 'HEAD:refs/heads/feat/x']);
        $this->sha      = trim($this->git($this->base . '/work', ['rev-parse', 'HEAD']));
        $this->checkout = $this->checkout();
    }

    /**
     * Remove the scratch repos
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->checkout->close();
        if (str_contains($this->base, 'kelpie-agent-edits-test-')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * Open the checkout and run the service with an agent that does what the closure does
     *
     * @param Closure $edit Edits the checkout, returns the closing words or null
     *
     * @return ProposedEdits
     */
    private function edits(Closure $edit): ProposedEdits
    {
        $logger         = $this->createStub(Logger::class);
        $this->checkout = $this->checkout();
        $this->assertTrue($this->checkout->open($this->remote, 'feat/x', $this->sha));
        $now = new DateTimeImmutable();

        return (new AgentEditsService($logger, new ScriptedFixerAgent($edit), new JsonService($logger)))
            ->edit($this->checkout, new Thread('d1', 100, 'alice', 'Add gate to the brief.', false, 1, $now, $now, 'pkg/brief.go', 3), 'openai/gpt-5.5', 'It is missing.');
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
