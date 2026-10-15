<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * Runs a process with proc_open: argv array (no shell), exact environment, wall-clock timeout
 *
 * Output is capped so a chatty process cannot exhaust memory.
 *
 * @see \Kelpie\Tests\Sandbox\ProcOpenProcessRunnerTest PHPUnit test case
 */
final class ProcOpenProcessRunner implements ProcessRunner
{
    private const int MAX_OUTPUT_BYTES = 1048576;

    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string[]              $argv           The program and its arguments (no shell)
     * @param array<string, string> $env            The whole environment of the process
     */
    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult
    {
        $process = proc_open(array_values($argv), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return new ProcessResult(-1, '', 'Could not start the process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out      = '';
        $err      = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $exit     = null;

        while (true) {
            $out .= $this->drain($pipes[1], strlen($out));
            $err .= $this->drain($pipes[2], strlen($err));

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = $status['exitcode']; // Valid only on the first call after exit
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                $this->logger->warning('Sandboxed process timed out', ['command' => $argv[0] ?? '']);
                proc_terminate($process, 9);
                break;
            }
            usleep(20000);
        }

        $out .= $this->drain($pipes[1], strlen($out));
        $err .= $this->drain($pipes[2], strlen($err));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);

        return new ProcessResult($timedOut ? -1 : ($exit ?? $closed), $out, $err, $timedOut);
    }

    /**
     * Read what is available, within the output cap
     *
     * @param mixed $pipe The pipe (a stream resource)
     * @param int   $have Bytes already collected
     *
     * @return string
     */
    private function drain(mixed $pipe, int $have): string
    {
        $data = is_resource($pipe) ? stream_get_contents($pipe) : false;
        if (!is_string($data)) {
            return '';
        }

        return substr($data, 0, max(0, self::MAX_OUTPUT_BYTES - $have));
    }
}
