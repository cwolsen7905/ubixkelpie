<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use ArrayObject;
use Kelpie\Agent\ClaudeCliFixerAgent;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\FixerRunnerBuilderService;
use Kelpie\Service\Autofix\SweepRunService;
use Kelpie\Service\Triage\TriageService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\SweepRunService
 *
 * What must hold:
 * - Without GitLab settings it fails before any request
 * - With KELPIE_AUTOFIX_ENABLED off it reports and writes nothing, to GitLab or to the job table
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\SweepRunService
 */
final class SweepRunServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(SweepRunService::class);
    }

    /**
     * A missing token fails without a request
     *
     * @return void
     */
    public function testRefusesMissingSettings(): void
    {
        $result = $this->service(new AutofixSettings(apiUrl: 'https://gitlab.test/api/v4'))->sweep();

        $this->assertFalse($result->success);
        $this->assertTrue($result->isError);
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * Disabled, it lists and reports and writes nothing
     *
     * @return void
     */
    public function testDisabledReportsOnly(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');

        $result = $this->service()->sweep();

        $this->assertTrue($result->success);
        $this->assertContains('ubixsys/ubixkelpie!3 d1abc123  would run (autofix disabled)', $result->lines);
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
        $this->assertSame(['Nothing needs resolution.'], $this->service()->sweep()->lines);
    }

    /**
     * A non-numeric --max-runs falls back to the settings' value
     *
     * @return void
     */
    public function testMaxRunsOptionIsOptional(): void
    {
        $this->assertTrue($this->service()->sweep('x')->success);
        $this->assertTrue($this->service()->sweep('0')->success);
    }

    /**
     * A fresh fake GitLab and query log for each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab  = new FakeGitlab();
        $this->queries = new ArrayObject();
    }

    /**
     * Build the service over the fake GitLab, a scripted process runner and a stubbed SQL service
     *
     * @param ?AutofixSettings $settings The settings; a configured bot, autofix off, when null
     *
     * @return SweepRunService
     */
    private function service(?AutofixSettings $settings = null): SweepRunService
    {
        $settings ??= new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real');
        $logger     = $this->createStub(Logger::class);
        $json       = new JsonService($logger);
        $factory    = new Psr17Factory();
        $queries    = $this->queries;
        $sql        = $this->createStub(SqlService::class);
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

        $jobs   = new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql));
        $client = new GitlabClientFactory($logger, $this->gitlab, $factory, $factory, $json, $settings);
        $runner = new ScriptedProcessRunner();
        $agent  = new ClaudeCliFixerAgent($logger, $runner, 'test-key-not-real');

        return new SweepRunService($logger, $client, new FixerRunnerBuilderService($logger, $client, $triage, $jobs, $runner, $agent, $settings), $jobs, $settings);
    }
}
