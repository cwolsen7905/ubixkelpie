<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use ArrayObject;
use Generator;
use Kelpie\Console\Command\FixCommand;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Kelpie\Triage\SafetyPolicy;
use Kelpie\Triage\TriagePrompt;
use Kelpie\Triage\TriageService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\FixCommand
 *
 * What must hold:
 * - It is named kelpie:fix and does nothing, not even a request, unless KELPIE_AUTOFIX_ENABLED is "true"
 * - It refuses bad ids, missing settings, unlisted projects and unknown threads
 * - A run goes through the job table: pending, running, finished
 * - It never sends a DELETE
 *
 * @coversDefaultClass \Kelpie\Console\Command\FixCommand
 */
final class FixCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(FixCommand::class);
    }

    /**
     * It is discovered as kelpie:fix
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:fix', $this->command()->getName());
    }

    /**
     * Off by default: no request and no SQL
     *
     * @return void
     */
    public function testDisabledByDefault(): void
    {
        putenv('KELPIE_AUTOFIX_ENABLED');
        $tester = new CommandTester($this->command());

        $this->assertSame(0, $tester->execute(['mr' => '3', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));

        $this->assertStringContainsString('disabled', $tester->getDisplay());
        $this->assertSame([], $this->gitlab->requests());
        $this->assertCount(0, $this->queries);
    }

    /**
     * Anything but the literal "true" is disabled
     *
     * @return void
     */
    public function testOnlyTrueEnables(): void
    {
        putenv('KELPIE_AUTOFIX_ENABLED=1');
        $tester = new CommandTester($this->command());

        $this->assertSame(0, $tester->execute(['mr' => '3', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * Bad input, a missing token and an unlisted project fail without a request
     *
     * @return void
     */
    public function testRefusesBadInput(): void
    {
        putenv('KELPIE_AUTOFIX_ENABLED=true');
        $tester = new CommandTester($this->command());

        $this->assertSame(1, $tester->execute(['mr' => 'x', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertSame(1, $tester->execute(['mr' => '3', 'discussion' => 'not hex!', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertSame(1, $tester->execute(['mr' => '3', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'other/project']));

        putenv('KELPIE_BOT_GITLAB_TOKEN');
        $this->assertSame(1, $tester->execute(['mr' => '3', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertSame([], $this->gitlab->requests());
        $this->assertCount(0, $this->queries);
    }

    /**
     * A thread that is not among the MR's unresolved threads is refused before any job exists
     *
     * @return void
     */
    public function testUnknownThread(): void
    {
        putenv('KELPIE_AUTOFIX_ENABLED=true');
        $tester = new CommandTester($this->command());

        $this->assertSame(1, $tester->execute(['mr' => '3', 'discussion' => 'abc12345', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertCount(0, $this->queries);
    }

    /**
     * A run walks the job pending to running to finished, and releases what it took on GitLab
     *
     * @return void
     */
    public function testRunWalksTheJobTable(): void
    {
        putenv('KELPIE_AUTOFIX_ENABLED=true');
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $tester = new CommandTester($this->command());

        // The scripted runner "clones" nothing, so the checkout cannot be verified: the run fails cleanly.
        $this->assertSame(1, $tester->execute(['mr' => '3', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));

        $this->assertStringContainsString('d1abc123  failed', $tester->getDisplay());
        $sql = implode("\n", $this->queries->getArrayCopy());
        $this->assertStringContainsString('INSERT INTO kelpie.autofix_jobs', $sql);
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
        putenv('GITLAB_API_URL=https://gitlab.test/api/v4');
        putenv('KELPIE_BOT_GITLAB_TOKEN=tok-not-real');
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
        foreach (['GITLAB_API_URL', 'KELPIE_BOT_GITLAB_TOKEN', 'OPENROUTER_API_KEY', 'OPENROUTER_API_URL', 'KELPIE_AUTOFIX_ENABLED'] as $name) {
            putenv($name);
        }
    }

    /**
     * Build the command over the fake GitLab, a model that says fix, a scripted process runner and a stubbed SQL service
     *
     * @return FixCommand
     */
    private function command(): FixCommand
    {
        $logger = $this->createStub(Logger::class);
        $json   = new JsonService($logger);
        $gitlab = $this->gitlab;
        $http   = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function (Request $request) use ($gitlab, $json): Psr7Response {
            if (str_contains((string) $request->getUri(), 'openrouter.test')) {
                $content = $json->encode(['class' => 'fix', 'reason' => 'A typo.']);

                return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
            }

            $response = $gitlab->sendRequest($request);

            return new Psr7Response($response->getStatusCode(), [], (string) $response->getBody());
        });

        $service = new TriageService(
            $logger,
            new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
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

        return new FixCommand(
            $logger,
            $http,
            new Psr17Factory(),
            new Psr17Factory(),
            $json,
            $service,
            new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql)),
            new ScriptedProcessRunner(),
        );
    }
}
