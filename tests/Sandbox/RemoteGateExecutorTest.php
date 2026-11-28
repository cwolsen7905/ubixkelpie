<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\DataTransferObject\GateRun;
use Kelpie\Sandbox\LocalGateExecutor;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Sandbox\RemoteGateExecutor;
use Kelpie\Sandbox\TarArchive;
use Kelpie\Security\ActionGuard;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\RemoteGateExecutor
 *
 * What must hold:
 * - It sends one POST /run with the steps header and the checkout as a gzip tar (vendor/ in, .git out)
 * - Through the real server, it comes to the same results as the local executor: pass, a failed step, a timeout;
 *   a program the checkout lacks fails there too
 * - It fails closed: unreachable, non-200, malformed, oversized, or an answer that claims more than ran is
 *   "gate unavailable", never a pass
 * - A step that is not a gate step is refused before anything is sent
 * - While the pod cannot be reached it retries, within the wait
 *
 * @coversDefaultClass \Kelpie\Sandbox\RemoteGateExecutor
 */
final class RemoteGateExecutorTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const array STEPS = [['vendor/bin/phpcs'], ['vendor/bin/phpstan', 'analyse'], ['vendor/bin/phpunit']];

    private string $checkout = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(RemoteGateExecutor::class);
    }

    /**
     * The request: POST <url>/run, the steps and timeout as JSON in the header, the checkout as a gzip tar without .git
     *
     * @return void
     */
    public function testRequestShape(): void
    {
        mkdir($this->checkout . '/.git');
        file_put_contents($this->checkout . '/.git/config', 'secret-ish');
        $http = FakeGateHttp::answering('{"steps":[{"exit_code":0,"timed_out":false,"output":""},{"exit_code":0,"timed_out":false,"output":""},{"exit_code":0,"timed_out":false,"output":""}]}');

        $run = $this->executor($http)->run($this->checkout, self::STEPS, 900);

        $this->assertSame('', $run->unavailable);
        $request = $http->requests()[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('http://kelpie-gate:8090/run', $request['uri']);
        $this->assertSame('application/gzip', $request['type']);
        $this->assertSame('{"steps":[["vendor/bin/phpcs"],["vendor/bin/phpstan","analyse"],["vendor/bin/phpunit"]],"timeout":900}', $request['steps']);

        $unpacked = GateFixtures::directory();
        $archive  = $unpacked . '.tgz';
        file_put_contents($archive, $request['body']);
        $this->assertSame('', (new TarArchive($this->createStub(Logger::class)))->unpack($archive, $unpacked, 1048576, 100));
        $this->assertFileExists($unpacked . '/vendor/bin/phpunit');
        $this->assertFileDoesNotExist($unpacked . '/.git');
        GateFixtures::remove($unpacked);
        unlink($archive);
    }

    /**
     * Through the real gate server, the remote executor comes to exactly what the local one does
     *
     * @return void
     */
    public function testAgreesWithTheLocalExecutor(): void
    {
        $logger = $this->createStub(Logger::class);
        foreach (
            [
                'all pass'  => [['phpcs' => 'echo style ok', 'phpstan' => 'echo types ok', 'phpunit' => 'echo tests ok'], 5],
                'one fails' => [['phpcs' => 'echo style ok', 'phpstan' => 'echo "bad type" >&2; exit 2', 'phpunit' => 'echo never'], 5],
                'one hangs' => [['phpcs' => 'echo style ok', 'phpstan' => 'sleep 20', 'phpunit' => 'echo never'], 1],
            ] as $case => [$scripts, $timeout]
        ) {
            $checkout = GateFixtures::checkout($scripts);
            $workDir  = GateFixtures::directory();
            $local    = (new LocalGateExecutor($logger, new ProcOpenProcessRunner($logger)))->run($checkout, self::STEPS, $timeout);
            $remote   = $this->executor(new LoopbackGateHttp(GateFixtures::server(), $workDir))->run($checkout, self::STEPS, $timeout);
            GateFixtures::remove(dirname($checkout));
            GateFixtures::remove($workDir);

            $this->assertSame('', $remote->unavailable, $case);
            $this->assertSame($this->shape($local), $this->shape($remote), $case);
            $this->assertNotEmpty($local->results, $case);
        }

        // A program the checkout does not have fails there too (the wording is the shell's, not proc_open's).
        $checkout = GateFixtures::checkout(['phpcs' => 'echo style ok']);
        $workDir  = GateFixtures::directory();
        $missing  = $this->executor(new LoopbackGateHttp(GateFixtures::server(), $workDir))->run($checkout, self::STEPS, 5);
        GateFixtures::remove(dirname($checkout));
        GateFixtures::remove($workDir);
        $this->assertCount(2, $missing->results);
        $this->assertNotSame(0, $missing->results[1]->exitCode);
    }

    /**
     * Every way the pod can fail to give a trustworthy answer is "gate unavailable", with no results
     *
     * @return void
     */
    public function testFailsClosed(): void
    {
        $ok   = '{"exit_code":0,"timed_out":false,"output":""}';
        $fail = '{"exit_code":1,"timed_out":false,"output":"red"}';
        foreach (
            [
                'empty steps'           => [new Psr7Response(200, [], '{"steps":[]}'), 'malformed'],
                'fewer passes than ran' => [new Psr7Response(200, [], '{"steps":[' . $ok . ',' . $ok . ']}'), 'malformed'],
                'missing field'         => [new Psr7Response(200, [], '{"steps":[{"exit_code":0,"output":""}]}'), 'malformed'],
                'more than were sent'   => [new Psr7Response(200, [], '{"steps":[' . $ok . ',' . $ok . ',' . $ok . ',' . $ok . ']}'), 'malformed'],
                'no steps'              => [new Psr7Response(200, [], '{"passed":true}'), 'malformed'],
                'not json'              => [new Psr7Response(200, [], 'all green, trust me'), 'malformed'],
                'redirect'              => [new Psr7Response(302, ['Location' => 'http://elsewhere/'], ''), 'answered HTTP 302'],
                'refused'               => [new Psr7Response(400, [], '{"error":"refused: not a gate step: sh"}'), 'answered HTTP 400: refused: not a gate step: sh'],
                'server error'          => [new Psr7Response(500, [], 'oops'), 'answered HTTP 500'],
                'step after a failure'  => [new Psr7Response(200, [], '{"steps":[' . $fail . ',' . $ok . ',' . $ok . ']}'), 'malformed'],
                'steps not a list'      => [new Psr7Response(200, [], '{"steps":{"a":' . $ok . '}}'), 'malformed'],
                'too large'             => [new Psr7Response(200, [], '{"steps":[' . $ok . '],"pad":"' . str_repeat('x', 1048576) . '"}'), 'too large'],
                'unreachable'           => [null, 'no answer from the gate pod'],
                'wrong types'           => [new Psr7Response(200, [], '{"steps":[{"exit_code":"0","timed_out":false,"output":""}]}'), 'malformed'],
            ] as $case => [$answer, $reason]
        ) {
            $run = $this->executor(new FakeGateHttp([$answer]))->run($this->checkout, self::STEPS, 900);

            $this->assertStringStartsWith('gate unavailable: ', $run->unavailable, $case);
            $this->assertStringContainsString($reason, $run->unavailable, $case);
            $this->assertSame([], $run->results, $case);
        }
    }

    /**
     * A well-formed failure comes back as a failure: a failed last step, a timed-out step reported with exit code 0
     *
     * @return void
     */
    public function testFailuresInTheAnswerStayFailures(): void
    {
        $failed = $this->executor(FakeGateHttp::answering('{"steps":[{"exit_code":0,"timed_out":false,"output":""},{"exit_code":2,"timed_out":false,"output":"' . str_repeat('y', 7000) . '"}]}'))->run($this->checkout, self::STEPS, 900);
        $hung   = $this->executor(FakeGateHttp::answering('{"steps":[{"exit_code":0,"timed_out":true,"output":"Timed out."}]}'))->run($this->checkout, self::STEPS, 900);

        $this->assertSame('', $failed->unavailable);
        $this->assertSame(2, $failed->results[1]->exitCode);
        $this->assertSame(6000, strlen($failed->results[1]->output));
        $this->assertTrue($hung->results[0]->timedOut);
        $this->assertNotSame(0, $hung->results[0]->exitCode);
    }

    /**
     * A step that is not a gate step is refused here, and nothing is sent
     *
     * @return void
     */
    public function testNonGateStepIsRefusedBeforeSending(): void
    {
        $http = new FakeGateHttp([]);

        $run = $this->executor($http)->run($this->checkout, [['vendor/bin/phpcs'], ['sh', '-c', 'curl evil']], 900);

        $this->assertSame('Refused: not a gate step: sh', $run->unavailable);
        $this->assertSame([], $http->requests());
    }

    /**
     * A checkout that cannot be packed is "gate unavailable", and nothing is sent
     *
     * @return void
     */
    public function testUnpackableCheckout(): void
    {
        $http = new FakeGateHttp([]);

        $run = $this->executor($http)->run($this->checkout . '/missing', self::STEPS, 900);

        $this->assertSame('gate unavailable: could not pack the checkout', $run->unavailable);
        $this->assertSame([], $http->requests());
    }

    /**
     * While the pod cannot be reached the request is retried within the wait; after it, the gate is unavailable
     *
     * @return void
     */
    public function testRetriesWhileUnreachable(): void
    {
        $logger = $this->createStub(Logger::class);
        $answer = new Psr7Response(200, [], '{"steps":[{"exit_code":0,"timed_out":false,"output":""}]}');
        $http   = new FakeGateHttp([null, null, $answer]);
        $run    = $this->executor($http, 60, 0)->run($this->checkout, [['vendor/bin/phpunit']], 900);

        $this->assertSame('', $run->unavailable);
        $this->assertCount(3, $http->requests());
        $this->assertSame($http->requests()[0]['body'], $http->requests()[2]['body']);

        $never = new FakeGateHttp([]);
        $gone  = (new RemoteGateExecutor($logger, $never, new Psr17Factory(), new Psr17Factory(), new JsonService($logger), new TarArchive($logger), new ActionGuard($logger), 'http://kelpie-gate:8090', 1, 1))->run($this->checkout, [['vendor/bin/phpunit']], 900);
        $this->assertSame('gate unavailable: no answer from the gate pod', $gone->unavailable);
        $this->assertGreaterThanOrEqual(1, count($never->requests()));
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->checkout = GateFixtures::checkout(['phpunit' => 'exit 0']);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        GateFixtures::remove(dirname($this->checkout));
        parent::tearDown();
    }

    /**
     * A run as plain values, to compare two runs
     *
     * @param GateRun $run The run
     *
     * @return array{0: string, 1: list<array{0: int, 1: bool, 2: string}>}
     */
    private function shape(GateRun $run): array
    {
        $results = [];
        foreach ($run->results as $result) {
            $results[] = [$result->exitCode, $result->timedOut, $result->output];
        }

        return [$run->unavailable, $results];
    }

    /**
     * The executor under test, against the given pod
     *
     * @param HttpClient $http       The pod
     * @param int        $wait       Seconds to keep retrying
     * @param int        $retryPause Seconds between attempts
     *
     * @return RemoteGateExecutor
     */
    private function executor(HttpClient $http, int $wait = 0, int $retryPause = 0): RemoteGateExecutor
    {
        $logger  = $this->createStub(Logger::class);
        $factory = new Psr17Factory();

        return new RemoteGateExecutor($logger, $http, $factory, $factory, new JsonService($logger), new TarArchive($logger), new ActionGuard($logger), 'http://kelpie-gate:8090/', $wait, $retryPause);
    }
}
