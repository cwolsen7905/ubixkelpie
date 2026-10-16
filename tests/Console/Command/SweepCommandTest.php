<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use ArrayObject;
use Kelpie\Console\Command\SweepCommand;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Kelpie\Triage\SafetyPolicy;
use Kelpie\Triage\TriagePrompt;
use Kelpie\Triage\TriageService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\SweepCommand
 *
 * What must hold:
 * - It is named kelpie:sweep
 * - Without GitLab settings it fails before any request
 * - With KELPIE_AUTOFIX_ENABLED off it reports and writes nothing, to GitLab or to the job table
 *
 * @coversDefaultClass \Kelpie\Console\Command\SweepCommand
 */
final class SweepCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private FakeGitlab $gitlab;

    /**
     * @var ArrayObject<int, string>
     */
    private ArrayObject $queries;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SweepCommand::class);
    }

    /**
     * It is discovered as kelpie:sweep
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:sweep', $this->command()->getName());
    }

    /**
     * A missing token fails without a request
     *
     * @return void
     */
    public function testRefusesMissingSettings(): void
    {
        putenv('KELPIE_BOT_GITLAB_TOKEN');
        $tester = new CommandTester($this->command());

        $this->assertSame(1, $tester->execute([]));
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * Disabled, it lists and reports and writes nothing
     *
     * @return void
     */
    public function testDisabledReportsOnly(): void
    {
        putenv('KELPIE_AUTOFIX_ENABLED');
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $tester = new CommandTester($this->command());

        $this->assertSame(0, $tester->execute([]));

        $this->assertStringContainsString('ubixsys/ubixkelpie!3 d1abc123  would run (autofix disabled)', $tester->getDisplay());
        foreach ($this->gitlab->requests() as $request) {
            $this->assertStringStartsWith('GET', $request);
        }
        $this->assertCount(0, $this->queries);
    }

    /**
     * An empty sweep says so
     *
     * @return void
     */
    public function testNothingToDo(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Nothing needs resolution.', $tester->getDisplay());
    }

    /**
     * Dummy settings
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab  = new FakeGitlab();
        $this->queries = new ArrayObject();
        putenv('GITLAB_API_URL=https://gitlab.test/api/v4');
        putenv('KELPIE_BOT_GITLAB_TOKEN=tok-not-real');
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (['GITLAB_API_URL', 'KELPIE_BOT_GITLAB_TOKEN', 'KELPIE_AUTOFIX_ENABLED'] as $name) {
            putenv($name);
        }
    }

    /**
     * @return SweepCommand
     */
    private function command(): SweepCommand
    {
        $logger  = $this->createStub(Logger::class);
        $json    = new JsonService($logger);
        $factory = new Psr17Factory();
        $queries = $this->queries;
        $sql     = $this->createStub(SqlService::class);
        $sql->method('query')->willReturnCallback(static function (string $statement) use ($queries): int {
            $queries[] = $statement;

            return 1;
        });

        $triage = new TriageService(
            $logger,
            new OpenRouterClient($logger, $this->gitlab, $factory, $factory, $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
        );

        return new SweepCommand(
            $logger,
            $this->gitlab,
            $factory,
            $factory,
            $json,
            $triage,
            new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql)),
            new ScriptedProcessRunner(),
        );
    }
}
