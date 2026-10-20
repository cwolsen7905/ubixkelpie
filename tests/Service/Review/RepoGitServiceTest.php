<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\Service\Review\RepoGitService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\RepoGitService
 *
 * What must hold:
 * - The diff comes from a given file, or from git between the base and the head with lockfiles, vendored and generated paths excluded
 * - A missing base commit or a failing git is null, never a made-up diff
 * - Lines "changed near" a thread only on evidence: a known earlier commit and a hunk within three lines of it
 *
 * @coversDefaultClass \Kelpie\Service\Review\RepoGitService
 */
final class RepoGitServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(RepoGitService::class);
    }

    /**
     * A diff file gives its text and the files it names
     *
     * @return void
     */
    public function testDiffFromAFile(): void
    {
        $diff = $this->service(new FakeGitRunner())->diff(SettingsBuilder::make());

        $this->assertNotNull($diff);
        $this->assertSame(['src/Transfer.php'], $diff->files);
        $this->assertStringContainsString('+        $source->withdraw($cents);', $diff->text);
    }

    /**
     * Git's diff excludes the paths that are never sent, and reads names and text between base and head
     *
     * @return void
     */
    public function testDiffFromGit(): void
    {
        $git = (new FakeGitRunner())->answer('diff --name-only', "a.php\nb.php\n")->answer('diff --no-color', "diff --git a/a.php b/a.php\n");

        $diff = $this->service($git)->diff(SettingsBuilder::make(['baseSha' => 'base1', 'diffFile' => '', 'repo' => '/repo']));

        $this->assertNotNull($diff);
        $this->assertSame(['a.php', 'b.php'], $diff->files);
        $this->assertSame("diff --git a/a.php b/a.php\n", $diff->text);
        $line = $git->commands()[0];
        $this->assertStringContainsString('-C /repo diff --name-only base1 abcdef1234567890abcdef1234567890abcdef12 -- .', $line);
        foreach (['**/composer.lock', '**/vendor/**', '**/node_modules/**', '**/*.min.js', '**/*.snap'] as $glob) {
            $this->assertStringContainsString(':(exclude,glob)' . $glob, $line);
        }
    }

    /**
     * No base commit, or git failing, is null
     *
     * @return void
     */
    public function testNoDiffWithoutBaseOrWhenGitFails(): void
    {
        $this->assertNull($this->service(new FakeGitRunner())->diff(SettingsBuilder::make(['diffFile' => ''])));
        $this->assertNull($this->service(new FakeGitRunner())->diff(SettingsBuilder::make(['baseSha' => 'base1', 'diffFile' => ''])));
    }

    /**
     * A file at a commit, and a commit's tree
     *
     * @return void
     */
    public function testShowAndTree(): void
    {
        $git = (new FakeGitRunner())->answer('show base1:CLAUDE.md', 'rules')->answer('ls-tree -r --name-only base1', "CLAUDE.md\nsrc/A.php\n");

        $this->assertSame('rules', $this->service($git)->show(SettingsBuilder::make(), 'base1', 'CLAUDE.md'));
        $this->assertNull($this->service($git)->show(SettingsBuilder::make(), 'base1', 'missing.md'));
        $this->assertSame(['CLAUDE.md', 'src/A.php'], $this->service($git)->tree(SettingsBuilder::make(), 'base1'));
        $this->assertSame([], $this->service(new FakeGitRunner())->tree(SettingsBuilder::make(), 'base1'));
    }

    /**
     * A hunk within three lines of the thread's line is a change; one further away is not
     *
     * @return void
     */
    public function testChangedNear(): void
    {
        $git = (new FakeGitRunner())->answer('cat-file -e old1^{commit}', '')->answer('diff -U0', "@@ -10,0 +14,2 @@ fn\n@@ -40 +50 @@\n");

        $service  = $this->service($git);
        $settings = SettingsBuilder::make();

        $this->assertTrue($service->changedNear($settings, 'src/A.php', 16, 'old1'));
        $this->assertTrue($service->changedNear($settings, 'src/A.php', 18, 'old1'));
        $this->assertFalse($service->changedNear($settings, 'src/A.php', 19, 'old1'));
        $this->assertTrue($service->changedNear($settings, 'src/A.php', 50, 'old1'));
        $this->assertFalse($service->changedNear($settings, 'src/A.php', 30, 'old1'));
    }

    /**
     * Unknown evidence answers no: no sha, the same sha, no line, or a commit that is not fetched
     *
     * @return void
     */
    public function testChangedNearIsNoWithoutEvidence(): void
    {
        $git      = (new FakeGitRunner())->answer('diff -U0', "@@ -1 +1 @@\n");
        $service  = $this->service($git);
        $settings = SettingsBuilder::make();

        $this->assertFalse($service->changedNear($settings, 'a.php', 1, ''));
        $this->assertFalse($service->changedNear($settings, 'a.php', 1, $settings->headSha));
        $this->assertFalse($service->changedNear($settings, 'a.php', 0, 'old1'));
        $this->assertFalse($service->changedNear($settings, 'a.php', 1, 'old1'));
    }

    /**
     * @param FakeGitRunner $git The fake git
     *
     * @return RepoGitService
     */
    private function service(FakeGitRunner $git): RepoGitService
    {
        return new RepoGitService($this->createStub(Logger::class), $git);
    }
}
