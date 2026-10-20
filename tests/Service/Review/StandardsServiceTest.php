<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\Service\Review\RepoGitService;
use Kelpie\Service\Review\StandardsService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\StandardsService
 *
 * What must hold:
 * - The standards are CLAUDE.md, AGENTS.md, CONTRIBUTING.md, .ai-review.md and docs/standards/**.md, and nothing else
 * - With a base commit they are read from it, so the merge request cannot rewrite them; without one, from the working tree
 * - Each file and the whole set are cut to a size budget
 * - A repository without them gives an empty string
 *
 * @coversDefaultClass \Kelpie\Service\Review\StandardsService
 */
final class StandardsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(StandardsService::class);
    }

    /**
     * The working tree's standards files are read, others are not
     *
     * @return void
     */
    public function testReadsTheWorkingTreeWithoutABase(): void
    {
        $repo = new TempRepo(['CLAUDE.md' => 'claude rules', 'README.md' => 'readme', 'docs/standards/php.md' => 'php rules', 'docs/other.md' => 'other', 'src/a.php' => 'x']);

        try {
            $text = $this->service(new FakeGitRunner())->read(SettingsBuilder::make(['repo' => $repo->path()]));
        } finally {
            $repo->remove();
        }

        $this->assertSame("## CLAUDE.md\n\nclaude rules\n\n## docs/standards/php.md\n\nphp rules\n\n", $text);
    }

    /**
     * With a base commit the standards come from it, not from the merge request's checkout
     *
     * @return void
     */
    public function testReadsTheBaseCommitNotTheCheckout(): void
    {
        $repo = new TempRepo(['CLAUDE.md' => 'REWRITTEN BY THE MR']);
        $git  = (new FakeGitRunner())->answer('ls-tree -r --name-only base1', "AGENTS.md\nCLAUDE.md\nsrc/a.php\n")->answer('show base1:CLAUDE.md', 'target rules')->answer('show base1:AGENTS.md', 'agent rules');

        try {
            $text = $this->service($git)->read(SettingsBuilder::make(['baseSha' => 'base1', 'diffFile' => '', 'repo' => $repo->path()]));
        } finally {
            $repo->remove();
        }

        $this->assertSame("## AGENTS.md\n\nagent rules\n\n## CLAUDE.md\n\ntarget rules\n\n", $text);
    }

    /**
     * A file is cut at 20 KiB and the set stops at its budget
     *
     * @return void
     */
    public function testSizeBudget(): void
    {
        $big  = str_repeat('x', 30000);
        $repo = new TempRepo(['AGENTS.md' => $big, 'CLAUDE.md' => $big, 'CONTRIBUTING.md' => $big, '.ai-review.md' => $big]);

        try {
            $text = $this->service(new FakeGitRunner())->read(SettingsBuilder::make(['repo' => $repo->path()]));
        } finally {
            $repo->remove();
        }

        $this->assertSame(3, substr_count($text, '## '));
        $this->assertLessThan(70000, strlen($text));
        $this->assertStringNotContainsString(str_repeat('x', 20481), $text);
    }

    /**
     * No standards is the empty string
     *
     * @return void
     */
    public function testNoneIsEmpty(): void
    {
        $repo = new TempRepo(['src/a.php' => 'x']);

        try {
            $this->assertSame('', $this->service(new FakeGitRunner())->read(SettingsBuilder::make(['repo' => $repo->path()])));
        } finally {
            $repo->remove();
        }
    }

    /**
     * @param FakeGitRunner $git The fake git
     *
     * @return StandardsService
     */
    private function service(FakeGitRunner $git): StandardsService
    {
        return new StandardsService($this->createStub(Logger::class), new RepoGitService($this->createStub(Logger::class), $git));
    }
}
