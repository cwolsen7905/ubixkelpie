<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\GateServeCommand;
use Kelpie\Sandbox\RemoteGateExecutor;
use Kelpie\Sandbox\TarArchive;
use Kelpie\Security\ActionGuard;
use Kelpie\Tests\Sandbox\GateFixtures;
use Kelpie\Tests\Sandbox\LoopbackGateHttp;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\HttpClient\CurlHttpClient;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\GateServeCommand
 *
 * What must hold:
 * - It is discovered as kelpie:gate-serve and refuses a port or body limit that is not a number
 * - It refuses a run limit that is not a positive number
 * - End to end, as `bin/ubix kelpie:gate-serve` on a free port: it empties its work and clean directories, writes the
 *   ready file, answers requests that are not a run and keeps listening, and serves a run (a step that passes and one
 *   that fails, through the real worker-side client). Not being the container's PID 1 here, its reset after the run
 *   fails closed: it kills nothing (a process the run left is still alive), removes the ready file and exits 1.
 *   Serving several runs, with resets that succeed, is in GateServerTest.
 *
 * @coversDefaultClass \Kelpie\Console\Command\GateServeCommand
 */
final class GateServeCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const int START_SECONDS = 20;

    private string $scratch = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(GateServeCommand::class);
    }

    /**
     * It is discovered as kelpie:gate-serve
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:gate-serve', (new GateServeCommand($this->createStub(Logger::class), GateFixtures::server()))->getName());
    }

    /**
     * A port or body limit that is not a number is refused before anything listens
     *
     * @return void
     */
    public function testRefusesBadSettings(): void
    {
        $tester = new CommandTester(new GateServeCommand($this->createStub(Logger::class), GateFixtures::server()));

        $this->assertSame(1, $tester->execute(['--port' => 'http', '--work-dir' => $this->scratch . '/work']));
        $this->assertSame(1, $tester->execute(['--port' => '70000', '--work-dir' => $this->scratch . '/work']));
        $this->assertSame(1, $tester->execute(['--port' => '0', '--max-body-bytes' => '0', '--work-dir' => $this->scratch . '/work']));
        $this->assertSame(1, $tester->execute(['--port' => '0', '--max-runs' => '0', '--work-dir' => $this->scratch . '/work']));
        $this->assertStringContainsString('The port must be', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->scratch . '/work');
    }

    /**
     * End to end on a free port: refusals keep it listening, a run is served, and off PID 1 the reset fails closed
     *
     * @return void
     */
    public function testServesARunAndFailsClosedOffPidOne(): void
    {
        $work  = $this->scratch . '/work';
        $clean = $this->scratch . '/tmp';
        $ready = $clean . '/gate-ready';
        mkdir($work . '/left/locked', 0700, true);
        file_put_contents($work . '/left/locked/old', 'x');
        chmod($work . '/left/locked', 0000);
        mkdir($clean);
        file_put_contents($clean . '/planted', 'x');
        mkdir($this->scratch . '/logs');

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/ubix', 'kelpie:gate-serve', '--listen', '127.0.0.1', '--port', '0', '--work-dir', $work, '--ready-file', $ready, '--clean', $clean],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->scratch . '/out.txt', 'w'], 2 => ['file', $this->scratch . '/err.txt', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            ['HOME' => $this->scratch, 'LOGGER_PATH' => $this->scratch . '/logs', 'PATH' => (string) getenv('PATH')],
        );
        $this->assertIsResource($process);

        try {
            $address = $this->waitForReady($ready, $process);
            $this->assertFileDoesNotExist($clean . '/planted');
            $this->assertDirectoryDoesNotExist($work . '/left');

            $this->assertStringStartsWith('HTTP/1.1 405 ', $this->raw($address, "GET /run HTTP/1.1\r\nHost: x\r\n\r\n"));
            $this->assertStringStartsWith('HTTP/1.1 400 ', $this->raw($address, "POST /run HTTP/1.1\r\nX-Pad: " . str_repeat('a', 20000) . "\r\n\r\n"));
            $this->assertStringStartsWith('HTTP/1.1 400 ', $this->raw($address, "POST /run HTTP/1.1\r\nContent-Length: 1\r\nX-Kelpie-Gate-Steps: {\"steps\":[[\"sh\",\"-c\",\"id\"]],\"timeout\":5}\r\n\r\nx"));
            $this->assertTrue(proc_get_status($process)['running']);

            $pidFile  = $this->scratch . '/left.pid';
            $checkout = GateFixtures::checkout(['phpcs' => 'sleep 300 > /dev/null 2>&1 & echo $! > ' . $pidFile . '; echo style ok', 'phpunit' => 'echo "1 failure"; exit 1']);
            $logger   = $this->createStub(Logger::class);
            $factory  = new Psr17Factory();
            $executor = new RemoteGateExecutor($logger, new CurlHttpClient($logger, $factory, 60), $factory, $factory, new JsonService($logger), new TarArchive($logger), new ActionGuard($logger), 'http://' . $address);
            $run      = $executor->run($checkout, [['vendor/bin/phpcs'], ['vendor/bin/phpunit']], 10);
            GateFixtures::remove(dirname($checkout));

            $this->assertSame('', $run->unavailable);
            $this->assertCount(2, $run->results);
            $this->assertSame([0, 'style ok'], [$run->results[0]->exitCode, $run->results[0]->output]);
            $this->assertSame([1, '1 failure'], [$run->results[1]->exitCode, $run->results[1]->output]);

            $this->assertSame(1, $this->waitForExit($process), (string) file_get_contents($this->scratch . '/err.txt'));
            $this->assertFileDoesNotExist($ready);
            $this->assertStringContainsString('not PID 1', (string) file_get_contents($this->scratch . '/logs/UbixCli.log'));
            $left = (int) trim((string) file_get_contents($pidFile));
            $this->assertGreaterThan(1, $left);
            $this->assertTrue(posix_kill($left, 0), 'off PID 1 the reset kills nothing');
        } finally {
            $left = is_file($this->scratch . '/left.pid') ? (int) trim((string) file_get_contents($this->scratch . '/left.pid')) : 0;
            if ($left > 1) {
                posix_kill($left, 9);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = GateFixtures::directory();
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        GateFixtures::remove($this->scratch);
        parent::tearDown();
    }

    /**
     * Wait until the ready file names the address
     *
     * @param string $ready   The ready file
     * @param mixed  $process The server (a process resource)
     *
     * @throws RuntimeException When it does not become ready in time
     *
     * @return string host:port
     */
    private function waitForReady(string $ready, mixed $process): string
    {
        $deadline = microtime(true) + self::START_SECONDS;
        while (microtime(true) < $deadline && is_resource($process) && proc_get_status($process)['running']) {
            $address = is_file($ready) ? trim((string) file_get_contents($ready)) : '';
            if ($address !== '') {
                return $address;
            }
            usleep(50000);
        }

        throw new RuntimeException('The gate server did not become ready: ' . file_get_contents($this->scratch . '/err.txt') . file_get_contents($this->scratch . '/out.txt'));
    }

    /**
     * Wait for the server to exit
     *
     * @param mixed $process The server (a process resource)
     *
     * @return int Its exit code; -1 when it is still running after 10 seconds
     */
    private function waitForExit(mixed $process): int
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && is_resource($process)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status['exitcode'];
            }
            usleep(50000);
        }

        return -1;
    }

    /**
     * Send raw bytes over TCP and read the whole answer
     *
     * @param string $address The address, host:port
     * @param string $request The raw request
     *
     * @throws RuntimeException When it cannot connect
     *
     * @return string
     */
    private function raw(string $address, string $request): string
    {
        $socket = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 5);
        if ($socket === false) {
            throw new RuntimeException('No connection: ' . $errorMessage . ' (' . $errorCode . ')');
        }
        stream_set_timeout($socket, 10);
        LoopbackGateHttp::write($socket, $request);
        $answer = (string) stream_get_contents($socket);
        fclose($socket);

        return $answer;
    }
}
