<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\GateStepResult;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Security\ActionGuard;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * The gate pod's server: takes one gate run over HTTP, runs it, answers, and is done
 *
 * It empties its work directory (and any other directory it is told to) before it listens, because an emptyDir
 * outlives a container restart. A connection that is not a well-formed `POST /run` is answered with an error and
 * closed, and the server keeps listening. Once a run's headers pass (method, path, size, steps), that connection is
 * the run: the ready file is removed, the body is unpacked into an empty directory, and after the answer `serve()`
 * returns 0 so the process exits and the pod starts clean. Every step must be a gate step (`ActionGuard::gateRefusal`)
 * or nothing is unpacked or run. Steps run with HOME and PATH only, and through a wrapper that closes every inherited
 * file descriptor above 2, so the repository's code cannot use the listening socket or the client's connection.
 *
 * It holds no token: the NetworkPolicy is the door, and the pod has nothing worth taking. See docs/security/gate-pod.md.
 *
 * @see \Kelpie\Tests\Sandbox\GateServerTest PHPUnit test case
 */
final class GateServer
{
    public const int MAX_STEPS = 10;

    public const int MAX_STEP_SECONDS = 1800;

    private const int MAX_HEAD_BYTES = 16384;

    private const int IO_TIMEOUT_SECONDS = 60;

    private const int MAX_UNPACKED_BYTES = 4294967296;

    private const int MAX_ENTRIES = 500000;

    /**
     * Runs the step with every inherited descriptor above 2 closed: `sh -c <this> gate-step <argv...>`
     */
    private const string CLOSE_FDS = 'for f in /dev/fd/*; do n=${f##*/}; case $n in 0|1|2|*[!0-9]*) ;; *) eval "exec $n>&-" 2>/dev/null ;; esac; done; exec "$@"';

    private const array REASONS = [
        100 => 'Continue',
        200 => 'OK',
        400 => 'Bad Request',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        411 => 'Length Required',
        413 => 'Content Too Large',
    ];

    /**
     * Constructor
     *
     * @param Logger        $logger      Logger
     * @param ActionGuard   $guard       Says what is a gate step
     * @param ProcessRunner $runner      Runs the steps (unguarded: the steps are checked here, then wrapped)
     * @param TarArchive    $archive     Unpacks the checkout
     * @param JsonService   $jsonService JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ActionGuard $guard,
        private readonly ProcessRunner $runner,
        private readonly TarArchive $archive,
        private readonly JsonService $jsonService,
    ) {
    }

    /**
     * Listen, serve one run, return
     *
     * @param string   $address      Where to listen, e.g. tcp://0.0.0.0:8090 (port 0 picks a free one)
     * @param string   $workDir      Where runs are unpacked; emptied first
     * @param string   $readyFile    Written with the address once listening, removed when a run starts
     * @param int      $maxBodyBytes The largest archive accepted
     * @param string[] $cleanDirs    Other directories to empty before listening (the pod's /tmp)
     *
     * @return int 0 after a run, 1 when it could not start
     */
    public function serve(string $address, string $workDir, string $readyFile, int $maxBodyBytes, array $cleanDirs = []): int
    {
        foreach ([$workDir, ...$cleanDirs] as $directory) {
            if (!$this->emptyDirectory($directory)) {
                $this->logger->error('Gate server could not empty a directory', ['directory' => $directory]);

                return 1;
            }
        }

        $server = stream_socket_server($address, $errorCode, $errorMessage);
        if ($server === false) {
            $this->logger->error('Gate server could not listen', ['address' => $address, 'error' => $errorMessage, 'code' => $errorCode]);

            return 1;
        }

        $name = (string) stream_socket_get_name($server, false);
        if (file_put_contents($readyFile, $name) === false) {
            $this->logger->error('Gate server could not write its ready file', ['file' => $readyFile]);
            fclose($server);

            return 1;
        }
        $this->logger->info('Gate server listening', ['address' => $name]);

        while (true) {
            $connection = stream_socket_accept($server, self::IO_TIMEOUT_SECONDS);
            if ($connection === false) {
                continue;
            }

            $ran = $this->handle($connection, $workDir, $readyFile, $maxBodyBytes);
            if (!$ran) {
                $this->drain($connection);
            }
            fclose($connection);
            if ($ran) {
                fclose($server);

                return 0;
            }
        }
    }

    /**
     * Answer one connection
     *
     * @param mixed  $connection   The connection (a stream resource)
     * @param string $workDir      Where the run is unpacked
     * @param string $readyFile    Removed when the run starts
     * @param int    $maxBodyBytes The largest archive accepted
     *
     * @return bool Whether this connection was the run (the server is then done)
     */
    public function handle(mixed $connection, string $workDir, string $readyFile, int $maxBodyBytes): bool
    {
        if (!is_resource($connection)) {
            return false;
        }
        stream_set_timeout($connection, self::IO_TIMEOUT_SECONDS);

        $head = $this->readHead($connection);
        if ($head === null) {
            return false;
        }
        [$method, $path, $headers] = $head;

        $refusal = match (true) {
            $method !== 'POST'                                              => [405, 'only POST'],
            $path !== RemoteGateExecutor::RUN_PATH                          => [404, 'only ' . RemoteGateExecutor::RUN_PATH],
            preg_match('/^\d{1,12}$/', $headers['content-length'] ?? '') !== 1 => [411, 'a Content-Length is required'],
            (int) $headers['content-length'] > $maxBodyBytes                => [413, 'the archive is larger than ' . $maxBodyBytes . ' bytes'],
            default                                                         => null,
        };
        if ($refusal !== null) {
            $this->answer($connection, $refusal[0], ['error' => $refusal[1]]);

            return false;
        }

        $run = $this->parseSteps($headers[strtolower(RemoteGateExecutor::STEPS_HEADER)] ?? '');
        if (is_string($run)) {
            $this->answer($connection, 400, ['error' => $run]);

            return false;
        }

        // From here this connection is the run: whatever happens, the server is done after it.
        if (is_file($readyFile)) {
            unlink($readyFile);
        }
        $this->logger->info('Gate run started', ['steps' => count($run['steps'])]);

        $error = $this->receive($connection, $workDir, (int) $headers['content-length'], strtolower($headers['expect'] ?? '') === '100-continue');
        if ($error !== '') {
            $this->answer($connection, 400, ['error' => $error]);

            return true;
        }

        $wrapped = [];
        foreach ($run['steps'] as $argv) {
            $wrapped[] = ['sh', '-c', self::CLOSE_FDS, 'gate-step', ...$argv];
        }
        $result = (new LocalGateExecutor($this->logger, $this->runner))->run($workDir . '/run/repo', $wrapped, $run['timeout']);

        $this->answer($connection, 200, ['steps' => array_map([$this, 'stepAnswer'], $result->results)]);
        $this->logger->info('Gate run answered', ['steps' => count($result->results)]);

        return true;
    }

    /**
     * Read the request line and headers
     *
     * @param mixed $connection The connection (a stream resource)
     *
     * @return ?array{0: string, 1: string, 2: array<string, string>} Method, path, headers by lower-case name; null when it is not HTTP
     */
    private function readHead(mixed $connection): ?array
    {
        $lines = [];
        $bytes = 0;
        while (is_resource($connection)) {
            $line = fgets($connection, self::MAX_HEAD_BYTES);
            if ($line === false) {
                return null;
            }
            $bytes += strlen($line);
            if ($bytes > self::MAX_HEAD_BYTES) {
                $this->answer($connection, 400, ['error' => 'the request head is too large']);

                return null;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            $lines[] = $line;
        }

        if (preg_match('~^([A-Z]+) (\S+) HTTP/1\.[01]$~', $lines[0] ?? '', $match) !== 1) {
            $this->answer($connection, 400, ['error' => 'not an HTTP request']);

            return null;
        }

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value]                   = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$match[1], $match[2], $headers];
    }

    /**
     * The steps and timeout of the steps header, or why they are refused
     *
     * @param string $header The header's value: {"steps": [[argv...], ...], "timeout": seconds}
     *
     * @return array{steps: array<int, string[]>, timeout: int}|string
     */
    private function parseSteps(string $header): array|string
    {
        try {
            $decoded = $this->jsonService->decode($header);
        } catch (DtoException $e) {
            $this->logger->debug('Gate run steps header is not JSON', ['error' => $e->getMessage()]);

            return 'the ' . RemoteGateExecutor::STEPS_HEADER . ' header is not JSON';
        }

        $timeout = $decoded['timeout'] ?? null;
        $entries = $decoded['steps'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > self::MAX_STEP_SECONDS) {
            return 'the timeout must be 1 to ' . self::MAX_STEP_SECONDS . ' seconds';
        }
        if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > self::MAX_STEPS) {
            return 'there must be 1 to ' . self::MAX_STEPS . ' steps';
        }

        $steps = [];
        foreach ($entries as $entry) {
            $argv = [];
            foreach (is_array($entry) && array_is_list($entry) ? $entry : [null] as $arg) {
                if (!is_string($arg)) {
                    return 'each step must be a list of strings';
                }
                $argv[] = $arg;
            }
            if ($argv === []) {
                return 'each step must be a list of strings';
            }
            $refusal = $this->guard->gateRefusal($argv);
            if ($refusal !== null) {
                $this->logger->warning('Gate run refused a step', ['program' => basename($argv[0]), 'reason' => $refusal]);

                return 'refused: ' . $refusal;
            }
            $steps[] = $argv;
        }

        return ['steps' => $steps, 'timeout' => $timeout];
    }

    /**
     * Read the body into the work directory and unpack it into `<workDir>/run/repo`
     *
     * @param mixed  $connection The connection (a stream resource)
     * @param string $workDir    The work directory
     * @param int    $length     The body's length
     * @param bool   $continue   Whether the client waits for "100 Continue"
     *
     * @return string Why it was refused, empty when it is unpacked
     */
    private function receive(mixed $connection, string $workDir, int $length, bool $continue): string
    {
        $archive = $workDir . '/upload.tar.gz';
        $target  = $workDir . '/run/repo';
        if (!$this->emptyDirectory($workDir) || !mkdir($target, 0700, true) || !is_resource($connection)) {
            return 'the work directory could not be prepared';
        }
        if ($continue) {
            $this->send($connection, "HTTP/1.1 100 Continue\r\n\r\n");
        }

        $file = fopen($archive, 'wb');
        if ($file === false) {
            return 'the work directory could not be prepared';
        }
        $copied = $length === 0 ? 0 : stream_copy_to_stream($connection, $file, $length);
        fclose($file);
        if ($copied !== $length) {
            return 'the body ended before its Content-Length';
        }

        $error = $this->archive->unpack($archive, $target, self::MAX_UNPACKED_BYTES, self::MAX_ENTRIES);
        unlink($archive);

        return $error;
    }

    /**
     * One step's result as the answer gives it
     *
     * @param GateStepResult $result The step
     *
     * @return array{exit_code: int, output: string, timed_out: bool}
     */
    private function stepAnswer(GateStepResult $result): array
    {
        return [
            'exit_code' => $result->exitCode,
            'output'    => $result->output,
            'timed_out' => $result->timedOut,
        ];
    }

    /**
     * Write a JSON answer and say the connection closes
     *
     * @param mixed                $connection The connection (a stream resource)
     * @param int                  $status     The status code
     * @param array<string, mixed> $payload    The JSON body
     *
     * @return void
     */
    private function answer(mixed $connection, int $status, array $payload): void
    {
        $body = $this->jsonService->encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->send($connection, 'HTTP/1.1 ' . $status . ' ' . (self::REASONS[$status] ?? 'Error') . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    }

    /**
     * Write all of it, or as much as the client takes before it goes away
     *
     * @param mixed  $connection The connection (a stream resource)
     * @param string $data       What to write
     *
     * @return void
     */
    private function send(mixed $connection, string $data): void
    {
        while ($data !== '' && is_resource($connection)) {
            $sent = stream_socket_sendto($connection, $data);
            if ($sent <= 0) {
                return;
            }
            $data = substr($data, $sent);
        }
    }

    /**
     * After an error answer: stop writing and read off what the client still sends (briefly, capped), so closing does
     * not reset the connection before the client has read the answer
     *
     * @param mixed $connection The connection (a stream resource)
     *
     * @return void
     */
    private function drain(mixed $connection): void
    {
        if (!is_resource($connection)) {
            return;
        }
        stream_socket_shutdown($connection, STREAM_SHUT_WR);
        stream_set_timeout($connection, 1);
        stream_get_contents($connection, self::MAX_HEAD_BYTES * 4);
    }

    /**
     * Make a directory exist and be empty, whatever the last run left in it (modes included)
     *
     * @param string $directory The directory
     *
     * @return bool
     */
    private function emptyDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            return mkdir($directory, 0700, true);
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && !$this->remove($directory . '/' . $name)) {
                return false;
            }
        }

        return (scandir($directory) ?: []) === ['.', '..'];
    }

    /**
     * Remove a file, link or directory tree, without following links
     *
     * @param string $path The path
     *
     * @return bool
     */
    private function remove(string $path): bool
    {
        if (is_link($path) || !is_dir($path)) {
            return unlink($path);
        }

        chmod($path, 0700);
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && !$this->remove($path . '/' . $name)) {
                return false;
            }
        }

        return rmdir($path);
    }
}
