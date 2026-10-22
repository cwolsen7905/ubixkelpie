<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use ArrayObject;
use Generator;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\FixerRunnerBuilderService;
use Kelpie\Service\Autofix\FixThreadService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\FixThreadService
 *
 * What must hold:
 * - It does nothing, not even a request, unless KELPIE_AUTOFIX_ENABLED is "true"
 * - It refuses bad ids, missing settings, unlisted projects and unknown threads
 * - A run goes through the job table: pending, running, finished
 * - It never sends a DELETE
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\FixThreadService
 */
final class FixThreadServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private FakeGitlab $gitlab;

    /**
     * The SQL statements the repository ran, in order
     *
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
        $this->testClassFollowingUbixStandards(FixThreadService::class);
    }

    /**
     * Off by default: no request and no SQL
     *
     * @return void
     */
    public function testDisabledByDefault(): void
    {
        $result = $this->service(false)->fix('3', 'd1abc123', '1', 'ubixsys/ubixkelpie');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('disabled', $result->lines[0]);
        $this->assertSame([], $this->gitlab->requests());
        $this->assertCount(0, $this->queries);
    }

    /**
     * Disabled is not an error
     *
     * @return void
     */
    public function testDisabledIsNotAnError(): void
    {
        $this->assertTrue($this->service(false)->fix('3', 'd1abc123', '1', 'ubixsys/ubixkelpie')->success);
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * Bad input, a missing token and an unlisted project fail without a request
     *
     * @return void
     */
    public function testRefusesBadInput(): void
    {
        $service = $this->service();

        $this->assertFalse($service->fix('x', 'd1abc123', '1', 'ubixsys/ubixkelpie')->success);
        $this->assertFalse($service->fix('3', 'not hex!', '1', 'ubixsys/ubixkelpie')->success);
        $this->assertFalse($service->fix('3', 'd1abc123', '1', 'other/project')->success);
        $this->assertTrue($service->fix('3', 'd1abc123', '1', 'other/project')->isError);

        $this->assertFalse($this->service(true, new AutofixSettings(apiUrl: 'https://gitlab.test/api/v4', enabled: true))->fix('3', 'd1abc123', '1', 'ubixsys/ubixkelpie')->success);
        $this->assertSame([], $this->gitlab->requests());
        $this->assertCount(0, $this->queries);
    }

    /**
     * Missing project and path fall back to the settings' CI values
     *
     * @return void
     */
    public function testDefaultsComeFromTheSettings(): void
    {
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: true, ciProjectId: '1', ciProjectPath: 'other/project');

        $result = $this->service(true, $settings)->fix('3', 'd1abc123');

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('KELPIE_AUTOFIX_PROJECTS', $result->lines[0]);
    }

    /**
     * A thread that is not among the MR's unresolved threads is refused before any job exists
     *
     * @return void
     */
    public function testUnknownThread(): void
    {
        $result = $this->service()->fix('3', 'abc12345', '1', 'ubixsys/ubixkelpie');

        $this->assertFalse($result->success);
        $this->assertSame(['No unresolved thread with that ID.'], $result->lines);
        $this->assertCount(0, $this->queries);
    }

    /**
     * A run walks the job pending to running to finished, and releases what it took on GitLab
     *
     * @return void
     */
    public function testRunWalksTheJobTable(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');

        // The scripted runner "clones" nothing, so the checkout cannot be verified: the run fails cleanly.
        $result = $this->service()->fix('3', 'd1abc123', '1', 'ubixsys/ubixkelpie');

        $this->assertFalse($result->success);
        $this->assertFalse($result->isError);
        $this->assertSame(['d1abc123  failed'], $result->lines);
        $sql = implode("\n", $this->queries->getArrayCopy());
        $this->assertStringContainsString('INSERT INTO ubixkelpie.autofix_jobs', $sql);
        $this->assertStringContainsString("SET status = 'running'", $sql);
        $this->assertStringContainsString('SET status = :status', $sql);
        $this->assertSame([], $this->gitlab->labels());
        foreach ($this->gitlab->requests() as $request) {
            $this->assertStringStartsNotWith('DELETE', $request);
        }
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
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1');
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (['OPENROUTER_API_KEY', 'OPENROUTER_API_URL', 'KELPIE_AUTOFIX_ENABLED'] as $name) {
            putenv($name);
        }
    }

    /**
     * Build the service over the fake GitLab, a model that says fix, a scripted process runner and a stubbed SQL service
     *
     * @param bool             $enabled  Whether autofix is on
     * @param ?AutofixSettings $settings The settings; a configured bot on ubixsys/ubixkelpie when null
     *
     * @return FixThreadService
     */
    private function service(bool $enabled = true, ?AutofixSettings $settings = null): FixThreadService
    {
        $settings ??= new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: $enabled);
        $logger     = $this->createStub(Logger::class);
        $json       = new JsonService($logger);
        $gitlab     = $this->gitlab;
        $http       = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function (Request $request) use ($gitlab, $json): Psr7Response {
            if (str_contains((string) $request->getUri(), 'openrouter.test')) {
                $content = $json->encode(['class' => 'fix', 'reason' => 'A typo.']);

                return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
            }

            $response = $gitlab->sendRequest($request);

            return new Psr7Response($response->getStatusCode(), [], (string) $response->getBody());
        });

        $triage = new TriageService(
            $logger,
            new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
            new LlmFamilyService($logger),
        );

        $queries = $this->queries;
        $sql     = $this->createStub(SqlService::class);
        $sql->method('query')->willReturnCallback(static function (string $statement) use ($queries): int {
            $queries[] = $statement;

            return 1;
        });
        $sql->method('lastInsertId')->willReturn('7');
        $sql->method('getColumn')->willReturn('0');
        // The open job is looked up before it exists (none) and after it was created (pending).
        $sql->method('getRows')->willReturnCallback(static function (string $statement) use ($queries): Generator {
            if (str_contains($statement, 'open_marker = 1') && str_contains(implode("\n", $queries->getArrayCopy()), 'INSERT INTO')) {
                yield [
                    'after_autofix' => '0',
                    'attempt'       => '0',
                    'commit_sha'    => null,
                    'date_created'  => '2026-10-15 12:00:00',
                    'discussion_id' => 'd1abc123',
                    'head_sha'      => str_repeat('a', 40),
                    'id'            => '7',
                    'mr_iid'        => '3',
                    'project_path'  => 'ubixsys/ubixkelpie',
                    'source'        => 'note',
                    'status'        => 'pending',
                    'thread_key'    => 'd1abc123',
                    'triage_class'  => null,
                ];
            }
        });

        $jobs    = new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql));
        $gitlab  = new GitlabClientFactory($logger, $http, new Psr17Factory(), new Psr17Factory(), $json, $settings);
        $open    = new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json);
        $propose = new ProposeEditsService($logger, $open, new ProposePrompt($logger), new LlmFamilyService($logger), $json);

        return new FixThreadService($logger, $gitlab, $jobs, new FixerRunnerBuilderService($logger, $gitlab, $triage, $jobs, new ScriptedProcessRunner(), $propose, new ThreadOutcomeSqlRepository($logger, $sql), $settings), $settings);
    }
}
