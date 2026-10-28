<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use ArrayObject;
use DateTimeImmutable;
use Generator;
use Kelpie\GitLab\DiscussionReader;
use Kelpie\GitLab\JobLogReader;
use Kelpie\GitLab\MergeRequestLister;
use Kelpie\Policy\EventFilter;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\EnqueueService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Autofix\SweepService;
use Kelpie\Tests\GitLab\FakeGitlab;
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
 * PHPUnit test case for \Kelpie\Service\Autofix\SweepService
 *
 * What must hold:
 * - Disabled, the sweep only reads: it reports candidates and writes and stores nothing
 * - Every thread goes through the webhook's filter, so the bot's own threads and the like are skipped
 * - A failed gate job's log becomes one marked, masked thread, opened once per job
 * - Threads that predate a Kelpie commit are left alone
 * - Enabled, the sweep only queues jobs for the worker and runs nothing
 * - A thread Kelpie already answered is not queued again
 * - At most the run limit of jobs is queued per sweep
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\SweepService
 */
final class SweepServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(SweepService::class);
    }

    /**
     * Disabled: candidates are reported and nothing is written, not even a pipeline thread
     *
     * @return void
     */
    public function testDisabledOnlyReads(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $this->gitlab->pipeline('failed', [$this->failedJob(55, 'phpunit', 'test')], 'boom');

        $lines = $this->service(false)->sweep(new DateTimeImmutable());

        $this->assertEqualsCanonicalizing([
            'ubixsys/ubixkelpie!3 d1abc123  would queue (autofix disabled)',
            'ubixsys/ubixkelpie!3 pipeline job 55  would open a thread (autofix disabled)',
        ], $lines);
        foreach ($this->gitlab->requests() as $request) {
            $this->assertStringStartsWith('GET', $request);
        }
        $this->assertCount(0, $this->queries);
    }

    /**
     * The webhook's filter decides: the bot's own thread is skipped with its reason
     *
     * @return void
     */
    public function testTheFilterDecides(): void
    {
        $this->gitlab->note('d1abc123', 'A note.', 'now', null, 'kelpie-bot');

        $this->assertSame(
            ['ubixsys/ubixkelpie!3 d1abc123  skipped: author is the bot or a Shepherd agent'],
            $this->service(false)->sweep(new DateTimeImmutable()),
        );
    }

    /**
     * A project that is not allow-listed is never listed
     *
     * @return void
     */
    public function testOnlyAllowListedProjects(): void
    {
        $service = $this->service(false, ['ubixsys/other']);

        // The fake answers any path, but the filter rejects the project path the MR is filed under
        $this->gitlab->note('d1abc123', 'Fix it.');
        $lines = $service->sweep(new DateTimeImmutable());

        $this->assertSame(['ubixsys/other!3 d1abc123  skipped: project not allow-listed'], $lines);
    }

    /**
     * Nothing to do reports nothing
     *
     * @return void
     */
    public function testNothingToDo(): void
    {
        $this->assertSame([], $this->service(true)->sweep(new DateTimeImmutable()));
    }

    /**
     * A pipeline that did not fail in a gate stage is skipped
     *
     * @return void
     */
    public function testDeployFailureIsSkipped(): void
    {
        $this->gitlab->pipeline('failed', [$this->failedJob(56, 'deploy-dev', 'deploy')]);

        $this->assertSame(
            ['ubixsys/ubixkelpie!3 pipeline  skipped: no failed gate job'],
            $this->service(true)->sweep(new DateTimeImmutable()),
        );
        $this->assertNotContains('POST /projects/ubixsys%2Fubixkelpie/merge_requests/3/discussions', $this->gitlab->requests());
    }

    /**
     * Enabled: the failed log is opened as one marked, masked thread and queued for the worker
     *
     * @return void
     */
    public function testFailedPipelineBecomesAThread(): void
    {
        $this->gitlab->pipeline('failed', [$this->failedJob(55, 'phpunit', 'test')], "FAIL Foo\nAuthorization: Bearer glpat-abcdefghijklmnopqrstuvwx\nCall @alice\n");

        $lines = $this->service(true)->sweep(new DateTimeImmutable());

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('  queued (job 7, enqueued)', $lines[0]);
        $bodies = [];
        foreach ($this->gitlab->notes() as $notes) {
            if (str_contains($notes[0]['body'], '<!-- kelpie:pipeline job=55 ')) {
                $bodies[] = $notes[0]['body'];
            }
        }
        $this->assertCount(1, $bodies);
        $body = $bodies[0];
        $this->assertStringContainsString('FAIL Foo', $body);
        $this->assertStringNotContainsString('glpat-', $body);
        $this->assertStringContainsString('[masked]', $body);
        $this->assertStringNotContainsString('@alice', $body);
        $this->assertStringContainsString('INSERT INTO ubixkelpie.autofix_jobs', implode("\n", $this->queries->getArrayCopy()));
    }

    /**
     * A job whose thread exists is not opened again
     *
     * @return void
     */
    public function testPipelineThreadIsOpenedOncePerJob(): void
    {
        $this->gitlab->pipeline('failed', [$this->failedJob(55, 'phpunit', 'test')], 'boom');
        $this->gitlab->note('ab000001', '<!-- kelpie:pipeline job=55 sha=' . str_repeat('a', 40) . " -->\nlog", 'now', null, 'kelpie-bot');

        $this->service(true)->sweep(new DateTimeImmutable());

        $pipelineNotes = 0;
        foreach ($this->gitlab->notes() as $notes) {
            foreach ($notes as $note) {
                $pipelineNotes += str_contains($note['body'], 'kelpie:pipeline job=55') ? 1 : 0;
            }
        }
        $this->assertSame(1, $pipelineNotes); // Only the triage summary note is new
    }

    /**
     * After a Kelpie commit, a thread older than it is not acted on
     *
     * @return void
     */
    public function testThreadsBeforeAnAutofixCommitAreSkipped(): void
    {
        $this->gitlab->commit("fix: x\n\nKelpie-Autofix: thread=d1 sha=abc", '2999-01-01T00:00:00.000Z');
        $this->gitlab->note('d1abc123', 'Old point.', '-1 hour');

        $this->assertSame(
            ['ubixsys/ubixkelpie!3 d1abc123  skipped: thread predates the autofix commit'],
            $this->service(true)->sweep(new DateTimeImmutable()),
        );
    }

    /**
     * Enabled: a thread becomes a job row and nothing runs, so the model and the fixer are never reached
     *
     * @return void
     */
    public function testThreadIsQueuedNotRun(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');

        $lines = $this->service(true)->sweep(new DateTimeImmutable());

        $this->assertSame(['ubixsys/ubixkelpie!3 d1abc123  queued (job 7, enqueued)'], $lines);
        $this->assertStringContainsString('INSERT INTO ubixkelpie.autofix_jobs', implode("\n", $this->queries->getArrayCopy()));
        foreach ($this->gitlab->requests() as $request) {
            $this->assertStringNotContainsString('openrouter', $request);
            $this->assertStringNotContainsString('labels', $request);
        }
    }

    /**
     * A thread Kelpie answered, with nothing written since, is not queued again
     *
     * @return void
     */
    public function testAnsweredThreadIsNotQueued(): void
    {
        $this->gitlab->note('d1abc123', 'Please fix the typo.');
        $this->gitlab->note('d1abc123', '<!-- kelpie:reply thread=d1abc123 class=needs_human -->', 'now', null, 'kelpie-bot');

        $this->assertSame(
            ['ubixsys/ubixkelpie!3 d1abc123  skipped: already answered'],
            $this->service(true)->sweep(new DateTimeImmutable()),
        );
        $this->assertCount(0, $this->queries);
    }

    /**
     * Past the run limit threads are deferred, not queued
     *
     * @return void
     */
    public function testRunLimit(): void
    {
        $this->gitlab->note('d1abc123', 'First.');
        $this->gitlab->note('d2abc123', 'Second.');

        $lines = $this->service(true, ['ubixsys/ubixkelpie'], 1)->sweep(new DateTimeImmutable());

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('deferred (sweep run limit)', $lines[1]);
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
        putenv('OPENROUTER_API_KEY');
        putenv('OPENROUTER_API_URL');
    }

    /**
     * @param int    $id    The job ID
     * @param string $name  The job name
     * @param string $stage The stage
     *
     * @return array<string, mixed>
     */
    private function failedJob(int $id, string $name, string $stage): array
    {
        return ['allow_failure' => false, 'id' => $id, 'name' => $name, 'stage' => $stage, 'status' => 'failed'];
    }

    /**
     * Build the sweep over the fake GitLab, a model that says fix, a scripted process runner and a stubbed SQL service
     *
     * @param bool     $enabled  Whether autofix is enabled
     * @param string[] $projects The allow-list
     * @param int      $maxRuns  The run limit
     *
     * @return SweepService
     */
    private function service(bool $enabled, array $projects = ['ubixsys/ubixkelpie'], int $maxRuns = 5): SweepService
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

        $factory = new Psr17Factory();
        $apiUrl  = 'https://gitlab.test/api/v4';
        $writer  = $gitlab->writer($logger);
        $queries = $this->queries;
        $sql     = $this->createStub(SqlService::class);
        $sql->method('query')->willReturnCallback(static function (string $statement) use ($queries): int {
            $queries[] = $statement;

            return 1;
        });
        $sql->method('lastInsertId')->willReturn('7');
        $sql->method('getColumn')->willReturn('0');
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

        $jobs = new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql));

        return new SweepService(
            $logger,
            $enabled,
            $projects,
            $maxRuns,
            new EnqueueService($logger, new EventFilter($logger), $jobs),
            new MergeRequestLister($logger, $http, $factory, $apiUrl, $json),
            new DiscussionReader($logger, $http, $factory, $apiUrl, $json),
            $writer,
            new JobLogReader($logger, $http, $factory, $apiUrl, $json),
            $jobs,
            new ReplyService($logger, $writer, 'kelpie-bot'),
        );
    }
}
