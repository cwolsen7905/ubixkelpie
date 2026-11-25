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
 * - An open-source project uses the public model when configured
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
     * An open-source project uses the public model when configured
     *
     * @return void
     */
    public function testOpenSourceLicenseUsesThePublicModel(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->gitlab()->withLicense('mit');
        $harness->model()->text((string) file_get_contents(SettingsBuilder::fixtures() . '/canned-ok.json'));
        $tester = new CommandTester($this->command($harness));

        putenv('AI_REVIEW_MODEL=openrouter/private-review-model');
        putenv('AI_REVIEW_MODEL_PUBLIC=openrouter/public-review-model');
        putenv('AI_REVIEW_GITLAB_TOKEN=review-token-not-real');
        putenv('CI_API_V4_URL=https://gitlab.test/api/v4');
        putenv('CI_PROJECT_ID=1');
        putenv('CI_MERGE_REQUEST_IID=3');
        putenv('CI_MERGE_REQUEST_TITLE=Add the transfer');
        putenv('CI_COMMIT_SHA=abcdef1234567890abcdef1234567890abcdef12');
        putenv('OPENROUTER_API_KEY=test-key-not-real');

        try {
            $exit = $tester->execute(['--diff' => SettingsBuilder::fixtures() . '/sample.diff']);
        } finally {
            putenv('AI_REVIEW_MODEL');
            putenv('AI_REVIEW_MODEL_PUBLIC');
            putenv('AI_REVIEW_GITLAB_TOKEN');
            putenv('CI_API_V4_URL');
            putenv('CI_PROJECT_ID');
            putenv('CI_MERGE_REQUEST_IID');
            putenv('CI_MERGE_REQUEST_TITLE');
            putenv('CI_COMMIT_SHA');
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame(0, $exit);
        $sent = $harness->model()->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('public-review-model', $sent[0]['model'] ?? null);
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
     * @param ?ReviewHarness $harness The harness to wire into the command
     *
     * @return ReviewCommand
     */
    private function command(?ReviewHarness $harness = null): ReviewCommand
    {
        $logger = $this->createStub(Logger::class);

        $harness ??= new ReviewHarness($logger);

        return new ReviewCommand($logger, new ReviewSettingsService($logger), $harness->service(), $harness->reviewGitlab());
    }
}
