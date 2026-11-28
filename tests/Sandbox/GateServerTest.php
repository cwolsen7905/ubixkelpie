<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\GateServer;
use Kelpie\Sandbox\TarArchive;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\GateServer
 *
 * What must hold:
 * - A request that is not a well-formed POST /run (method, path, length, size, steps) is answered with an error and is
 *   not the run: the server keeps listening and nothing is unpacked
 * - A step that is not a gate step refuses the whole run with 400 before anything is unpacked or started
 * - Once the headers pass, the connection is the run: the ready file goes, an archive entry leaving the work directory
 *   is refused with 400, and a good archive's steps run and are answered as JSON
 * - The steps see HOME and PATH only, and none of the server's sockets
 *
 * Serving one run on a real port and exiting, and a head too large, are in GateServeCommandTest (a real TCP socket:
 * a socket pair's buffer is too small to hold a request larger than it before the server reads).
 *
 * @coversDefaultClass \Kelpie\Sandbox\GateServer
 */
final class GateServerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string STEPS = '{"steps":[["vendor/bin/phpcs"],["vendor/bin/phpunit"]],"timeout":10}';

    private string $workDir = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(GateServer::class);
    }

    /**
     * Wrong method, wrong path, no length, too large, not HTTP: an error, and not the run
     *
     * @return void
     */
    public function testMalformedRequestsAreNotTheRun(): void
    {
        foreach (
            [
                'bad JSON'     => ["POST /run HTTP/1.1\r\nContent-Length: 1\r\nX-Kelpie-Gate-Steps: [oops\r\n\r\nx", 400],
                'bad length'   => ["POST /run HTTP/1.1\r\nContent-Length: -1\r\n\r\n", 411],
                'empty argv'   => ["POST /run HTTP/1.1\r\nContent-Length: 1\r\nX-Kelpie-Gate-Steps: {\"steps\":[[]],\"timeout\":5}\r\n\r\nx", 400],
                'GET'          => ["GET /run HTTP/1.1\r\n\r\n", 405],
                'long timeout' => ["POST /run HTTP/1.1\r\nContent-Length: 1\r\nX-Kelpie-Gate-Steps: {\"steps\":[[\"vendor/bin/phpunit\"]],\"timeout\":99999}\r\n\r\nx", 400],
                'many steps'   => ["POST /run HTTP/1.1\r\nContent-Length: 1\r\nX-Kelpie-Gate-Steps: {\"steps\":" . str_repeat('[["vendor/bin/phpunit"]', 1) . str_repeat(',["vendor/bin/phpunit"]', 10) . "],\"timeout\":5}\r\n\r\nx", 400],
                'no length'    => ["POST /run HTTP/1.1\r\n\r\n", 411],
                'no steps'     => ["POST /run HTTP/1.1\r\nContent-Length: 1\r\n\r\nx", 400],
                'not HTTP'     => ["hello\r\n\r\n", 400],
                'no timeout'   => ["POST /run HTTP/1.1\r\nContent-Length: 1\r\nX-Kelpie-Gate-Steps: {\"steps\":[[\"vendor/bin/phpunit\"]]}\r\n\r\nx", 400],
                'number argv'  => ["POST /run HTTP/1.1\r\nContent-Length: 1\r\nX-Kelpie-Gate-Steps: {\"steps\":[[1]],\"timeout\":5}\r\n\r\nx", 400],
                'PUT'          => ["PUT /run HTTP/1.1\r\nContent-Length: 1\r\n\r\nx", 405],
                'query'        => ["POST /run?x=1 HTTP/1.1\r\nContent-Length: 1\r\n\r\nx", 404],
                'too large'    => ["POST /run HTTP/1.1\r\nContent-Length: 1048577\r\n\r\n", 413],
                'wrong path'   => ["POST /exec HTTP/1.1\r\nContent-Length: 1\r\n\r\nx", 404],
            ] as $case => [$request, $status]
        ) {
            [$ran, $answer] = $this->exchange($request);

            $this->assertFalse($ran, $case);
            $this->assertStringStartsWith('HTTP/1.1 ' . $status . ' ', $answer, $case);
            $this->assertStringContainsString('"error":', $answer, $case);
            $this->assertFileExists($this->workDir . '/ready', $case);
            $this->assertDirectoryDoesNotExist($this->workDir . '/run', $case);
        }
    }

    /**
     * A step that is not a gate step refuses the run before anything is unpacked or started
     *
     * @return void
     */
    public function testNonGateStepRefusesTheRun(): void
    {
        foreach (['["sh","-c","id"]', '["git","push","origin","HEAD:refs/heads/x"]', '["make","check","deploy"]', '["./vendor/bin/phpunit"]'] as $step) {
            $body = $this->archive([GateFixtures::entry('marker', '0', 'x')]);

            [$ran, $answer] = $this->exchange($this->request('{"steps":[["vendor/bin/phpunit"],' . $step . '],"timeout":5}', $body));

            $this->assertFalse($ran, $step);
            $this->assertStringStartsWith('HTTP/1.1 400 ', $answer, $step);
            $this->assertStringContainsString('refused: ', $answer, $step);
            $this->assertFileDoesNotExist($this->workDir . '/run/repo/marker', $step);
        }
    }

    /**
     * An archive entry that leaves the work directory refuses the run, and this connection was the run
     *
     * @return void
     */
    public function testEscapingArchiveIsRefused(): void
    {
        foreach (['../escaped', '/' . ltrim($this->workDir, '/') . '/escaped'] as $name) {
            file_put_contents($this->workDir . '/ready', 'x');

            [$ran, $answer] = $this->exchange($this->request(self::STEPS, $this->archive([GateFixtures::entry($name, '0', 'x')])));

            $this->assertTrue($ran, $name);
            $this->assertStringStartsWith('HTTP/1.1 400 ', $answer, $name);
            $this->assertStringContainsString('leaves the work directory', $answer, $name);
            $this->assertFileDoesNotExist($this->workDir . '/escaped', $name);
            $this->assertFileDoesNotExist($this->workDir . '/run/escaped', $name);
            $this->assertFileDoesNotExist($this->workDir . '/ready', $name);
        }
    }

    /**
     * A body shorter than its Content-Length is refused, and was the run
     *
     * @return void
     */
    public function testShortBodyIsRefused(): void
    {
        $request = "POST /run HTTP/1.1\r\nContent-Length: 1000\r\nX-Kelpie-Gate-Steps: " . self::STEPS . "\r\n\r\nshort";

        [$ran, $answer] = $this->exchange($request, true);

        $this->assertTrue($ran);
        $this->assertStringContainsString('the body ended before its Content-Length', $answer);
    }

    /**
     * A good run: the steps run in order with HOME and PATH only, see no socket, and are answered as JSON
     *
     * @return void
     */
    public function testRunsTheSteps(): void
    {
        $checkout = GateFixtures::checkout([
            'phpcs'   => 'echo "home=$HOME"; env | sort; for f in /dev/fd/*; do [ -S "$f" ] && echo "SOCKET $f"; done; exit 0',
            'phpunit' => 'echo "3 failures" >&2; exit 1',
        ]);
        $archive  = $this->workDir . '-in.tgz';
        $this->assertSame('', (new TarArchive($this->createStub(Logger::class)))->pack($checkout, $archive));
        $body = (string) file_get_contents($archive);
        unlink($archive);
        GateFixtures::remove(dirname($checkout));

        [$ran, $answer] = $this->exchange($this->request(self::STEPS, $body, true));

        $this->assertTrue($ran);
        $this->assertStringStartsWith("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\n", $answer);
        $json = (array) json_decode(explode("\r\n\r\n", $answer, 3)[2], true); // phpcs:ignore Generic.PHP.ForbiddenFunctions -- the raw answer, as the worker reads it
        $this->assertIsArray($json['steps']);
        $this->assertCount(2, $json['steps']);
        [$first, $second] = $json['steps'];
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame(0, $first['exit_code']);
        $this->assertIsString($first['output']);
        $this->assertStringContainsString('home=' . $this->workDir . '/run', $first['output']);
        $this->assertStringNotContainsString('SOCKET', $first['output']);
        $this->assertStringNotContainsString('KELPIE', $first['output']);
        $this->assertSame(['exit_code' => 1, 'output' => '3 failures', 'timed_out' => false], $second);
        $this->assertFileDoesNotExist($this->workDir . '/ready');
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = GateFixtures::directory();
        file_put_contents($this->workDir . '/ready', 'x');
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        GateFixtures::remove($this->workDir);
        parent::tearDown();
    }

    /**
     * A POST /run with the steps header and body
     *
     * @param string $steps    The steps header
     * @param string $body     The body
     * @param bool   $continue Whether to ask for 100 Continue
     *
     * @return string
     */
    private function request(string $steps, string $body, bool $continue = false): string
    {
        return "POST /run HTTP/1.1\r\nContent-Length: " . strlen($body) . "\r\nX-Kelpie-Gate-Steps: " . $steps . "\r\n" . ($continue ? "Expect: 100-continue\r\n" : '') . "\r\n" . $body;
    }

    /**
     * Hand-built entries as a gzip tar body
     *
     * @param string[] $entries The entries
     *
     * @return string
     */
    private function archive(array $entries): string
    {
        return (string) gzencode(implode('', $entries) . str_repeat("\0", 1024));
    }

    /**
     * Send raw bytes to the server over a socket pair and read what it answers
     *
     * @param string $request  The raw request
     * @param bool   $shutdown Whether to close the sending side after it (a client that stops early)
     *
     * @throws RuntimeException When no socket pair can be made
     *
     * @return array{0: bool, 1: string} Whether it was the run, and the raw answer
     */
    private function exchange(string $request, bool $shutdown = false): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('No socket pair.');
        }
        [$client, $server] = $pair;
        LoopbackGateHttp::write($client, $request);
        if ($shutdown) {
            stream_socket_shutdown($client, STREAM_SHUT_WR);
        }
        $ran = GateFixtures::server()->handle($server, $this->workDir, $this->workDir . '/ready', 1048576);
        fclose($server);
        $answer = (string) stream_get_contents($client);
        fclose($client);

        return [$ran, $answer];
    }
}
