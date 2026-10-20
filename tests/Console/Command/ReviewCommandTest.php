<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\ReviewCommand;
use Kelpie\Service\Review\ReviewSettingsService;
use Kelpie\Tests\Service\Review\ReviewHarness;
use Kelpie\Tests\Service\Review\SettingsBuilder;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\ReviewCommand
 *
 * What must hold:
 * - It is named kelpie:review
 * - A mode other than advisory or blocking exits 2 with a message
 * - A dry run over a diff file and a canned reply prints the review and exits 0, calling no GitLab
 * - It exits with the review's code: 75 when it could not run
 *
 * @coversDefaultClass \Kelpie\Console\Command\ReviewCommand
 */
final class ReviewCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewCommand::class);
    }

    /**
     * It is discovered as kelpie:review
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:review', $this->command()->getName());
    }

    /**
     * An unknown mode is a usage error
     *
     * @return void
     */
    public function testUnknownModeExitsTwo(): void
    {
        putenv('AI_REVIEW_MODE=strict');
        $tester = new CommandTester($this->command());

        try {
            $exit = $tester->execute([]);
        } finally {
            putenv('AI_REVIEW_MODE');
        }

        $this->assertSame(2, $exit);
        $this->assertStringContainsString("AI_REVIEW_MODE must be advisory or blocking, got 'strict'", $tester->getDisplay());
    }

    /**
     * A dry run prints the review JSON, the threads and the summary, and exits 0
     *
     * @return void
     */
    public function testDryRunPrintsTheReview(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute(['--canned' => SettingsBuilder::fixtures() . '/canned-ok.json', '--diff' => SettingsBuilder::fixtures() . '/sample.diff', '--dry-run' => true]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('=== review JSON ===', $display);
        $this->assertSame(3, substr_count($display, '--- would open a thread ---'));
        $this->assertStringContainsString('--- would post the summary note ---', $display);
    }

    /**
     * A review that cannot run exits 75
     *
     * @return void
     */
    public function testUnconfiguredExits75(): void
    {
        putenv('AI_REVIEW_GITLAB_TOKEN');
        $tester = new CommandTester($this->command());

        $exit = $tester->execute(['--canned' => SettingsBuilder::fixtures() . '/canned-ok.json', '--diff' => SettingsBuilder::fixtures() . '/sample.diff']);

        $this->assertSame(75, $exit);
    }

    /**
     * @return ReviewCommand
     */
    private function command(): ReviewCommand
    {
        $logger = $this->createStub(Logger::class);

        return new ReviewCommand($logger, new ReviewSettingsService($logger), (new ReviewHarness($logger))->service());
    }
}
