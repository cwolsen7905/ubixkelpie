<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\FixIssueCommand;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\Tests\Service\Autofix\FakeFixGitlab;
use Kelpie\Tests\Service\Autofix\IssueFixFixtures;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\FixIssueCommand
 *
 * What must hold:
 * - It is named kelpie:fix-issue
 * - It refuses a missing or bad issue number or path before touching anything
 * - It prints the outcome of the service; a Retry is an error and exits 1
 *
 * @coversDefaultClass \Kelpie\Console\Command\FixIssueCommand
 */
final class FixIssueCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FixIssueCommand::class);
    }

    /**
     * It is discovered as kelpie:fix-issue
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:fix-issue', $this->command(false)->getName());
    }

    /**
     * Bad input is an error and reaches no service
     *
     * @return void
     */
    public function testRefusesBadInput(): void
    {
        $gitlab = new FakeFixGitlab();

        foreach ([['issue' => 'abc', '--path' => 'org/proj'], ['issue' => '0', '--path' => 'org/proj'], ['issue' => '7']] as $input) {
            $tester = new CommandTester($this->command(true, $gitlab));

            $this->assertSame(1, $tester->execute($input));
            $this->assertStringContainsString('Give the issue number', $tester->getDisplay());
        }
        $this->assertSame([], $gitlab->requests());
    }

    /**
     * Disabled is not an error: the service does nothing and says skipped
     *
     * @return void
     */
    public function testDisabledIsSkipped(): void
    {
        $tester = new CommandTester($this->command(false));

        $this->assertSame(0, $tester->execute(['issue' => '7', '--path' => 'org/proj']));
        $this->assertStringContainsString('Issue fix: skipped', $tester->getDisplay());
    }

    /**
     * Nothing done anywhere is an error
     *
     * @return void
     */
    public function testRetryIsAnError(): void
    {
        $tester = new CommandTester($this->command(true, (new FakeFixGitlab())->failIssue(), true));

        $this->assertSame(1, $tester->execute(['issue' => '7', '--path' => 'org/proj']));
        $this->assertStringContainsString('Issue fix: retry', $tester->getDisplay());
    }

    /**
     * @param bool           $enabled  Whether autofix is on
     * @param ?FakeFixGitlab $gitlab   The fake GitLab
     * @param bool           $enrolled Whether the project row exists
     *
     * @return FixIssueCommand
     */
    private function command(bool $enabled, ?FakeFixGitlab $gitlab = null, bool $enrolled = false): FixIssueCommand
    {
        $logger   = $this->createStub(Logger::class);
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'bot-token', enabled: $enabled);

        return new FixIssueCommand($logger, IssueFixFixtures::builder($logger, $gitlab ?? new FakeFixGitlab(), $settings, $enrolled));
    }
}
