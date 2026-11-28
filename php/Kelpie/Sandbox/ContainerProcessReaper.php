<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Closure;
use Kelpie\Sandbox\ProcessReaperInterface as ProcessReaper;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * Kills every other process in the gate pod's container, from PID 1, and checks /proc that none is left
 *
 * The gate server is the container's PID 1 (the manifest's `command` starts `php` with no shell in front). From
 * PID 1, `kill(-1, SIGKILL)` reaches every process in the container's PID namespace except PID 1 itself, so whatever a
 * run left behind (a background process, a daemon that detached) is gone. The kill is repeated until /proc lists
 * nothing but this process and zombies (dead, waiting to be reaped: they run nothing), or the attempts run out.
 *
 * The kill is `posix_kill(-1, SIGKILL)` when the posix extension is loaded. The Kelpie image has no posix, so there it
 * is a child process, `<kill> -9 -1` (argv, no shell, the `kill` program found on PATH or in /bin, /usr/bin): from a
 * child of PID 1, kill(-1) reaches everything but PID 1 and the kill process itself, which then exits. The child
 * inherits the listening socket (only 0-2 are set up), which does not matter: it is the image's own `kill`, it runs
 * no repository code, and it exits at once. Exit 1 is what kill says when there was nothing left to signal; the /proc
 * check decides either way. A kill program that cannot be found, started, or that fails otherwise is a refusal.
 *
 * Anywhere else it refuses without killing anything: from a process that is not PID 1, `kill(-1)` would reach every
 * process of the user outside the container, and the check could not prove the container clear. A refusal makes the
 * server exit non-zero, so Kubernetes replaces the pod.
 *
 * @see \Kelpie\Tests\Sandbox\ContainerProcessReaperTest PHPUnit test case
 */
final class ContainerProcessReaper implements ProcessReaper
{
    private const int SIGKILL = 9;

    private const int ATTEMPTS = 20;

    private const int PAUSE_MICROSECONDS = 100000;

    private const int KILL_TIMEOUT_SECONDS = 10;

    private const array KILL_PATHS = ['/bin/kill', '/usr/bin/kill'];

    /**
     * Constructor
     *
     * @param Logger        $logger   Logger
     * @param ProcessRunner $runner   Runs the kill program when posix is not loaded (the plain runner)
     * @param string        $procDir  Where the process list is read
     * @param int           $pid      This process's PID as the container sees it; 0 reads getmypid()
     * @param ?Closure      $kill     Sends SIGKILL to every other process (`Closure(): bool`); null picks posix or the program
     * @param ?bool         $posix    Whether posix_kill() is there; null checks
     * @param string        $killPath The kill program; '' looks it up on PATH, then in /bin and /usr/bin
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $runner,
        private readonly string $procDir = '/proc',
        private readonly int $pid = 0,
        private readonly ?Closure $kill = null,
        private readonly ?bool $posix = null,
        private readonly string $killPath = '',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function reap(): string
    {
        $pid = $this->pid ?: (int) getmypid();
        if ($pid !== 1) {
            return 'the gate server is not PID 1, so it cannot clear the container';
        }
        $posix    = $this->kill === null && ($this->posix ?? function_exists('posix_kill'));
        $killPath = $this->kill === null && !$posix ? $this->killProgram() : '';
        if ($this->kill === null && !$posix && $killPath === '') {
            return 'neither posix_kill nor a kill program is available';
        }

        $survivors = [];
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $error = $this->killAll($posix, $killPath);
            if ($error !== '') {
                return $error;
            }
            usleep(self::PAUSE_MICROSECONDS);
            $survivors = $this->survivors($pid);
            if ($survivors === null) {
                return 'the process list (' . $this->procDir . ') cannot be read';
            }
            if ($survivors === []) {
                return '';
            }
        }

        $this->logger->error('Gate pod processes survived the reset', ['pids' => $survivors]);

        return 'processes survived the reset: ' . implode(', ', $survivors);
    }

    /**
     * Send SIGKILL to every other process
     *
     * @param bool   $posix    Whether to use posix_kill()
     * @param string $killPath Otherwise, the kill program
     *
     * @return string Why it could not be sent, empty when it was (or there was nothing to signal)
     */
    private function killAll(bool $posix, string $killPath): string
    {
        if ($this->kill !== null || $posix) {
            $sent = $this->kill === null ? posix_kill(-1, self::SIGKILL) : ($this->kill)();
            if ($sent === false) {
                // ESRCH: there was nothing to signal. Whatever it was, the check after decides.
                $this->logger->debug('Gate pod kill found nothing to signal');
            }

            return '';
        }

        $result = $this->runner->run([$killPath, '-9', '-1'], '/', ['PATH' => '/usr/local/bin:/usr/bin:/bin'], self::KILL_TIMEOUT_SECONDS);
        if ($result->timedOut || $result->exitCode < 0 || $result->exitCode > 1) {
            $this->logger->error('Gate pod kill program failed', ['exit' => $result->exitCode, 'stderr' => substr($result->stderr, 0, 200)]);

            return 'the kill program failed (exit ' . $result->exitCode . ')';
        }

        return '';
    }

    /**
     * The kill program: the injected path, else `kill` on PATH, else /bin/kill or /usr/bin/kill
     *
     * @return string Empty when there is none
     */
    private function killProgram(): string
    {
        if ($this->killPath !== '') {
            return is_file($this->killPath) && is_executable($this->killPath) ? $this->killPath : '';
        }

        $candidates = [];
        foreach (explode(':', (string) getenv('PATH')) as $directory) {
            if (str_starts_with($directory, '/')) {
                $candidates[] = rtrim($directory, '/') . '/kill';
            }
        }
        foreach ([...$candidates, ...self::KILL_PATHS] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * The processes other than this one that are not zombies, or null when the list cannot be read
     *
     * @param int $pid This process
     *
     * @return ?int[]
     */
    private function survivors(int $pid): ?array
    {
        $entries = is_dir($this->procDir) ? scandir($this->procDir) : false;
        if ($entries === false) {
            return null;
        }

        $survivors = [];
        foreach ($entries as $entry) {
            if (preg_match('/^\d+$/', $entry) !== 1 || (int) $entry === $pid) {
                continue;
            }
            // The state follows the command name, which is in parentheses and may itself hold ") ".
            $statPath = $this->procDir . '/' . $entry . '/stat';
            $stat     = is_readable($statPath) ? (string) file_get_contents($statPath) : '';
            if ($stat === '') {
                // Only a process that is really gone is skipped; one whose stat cannot be read (hidepid, a permission
                // change) still counts, so the reset fails closed.
                if (is_dir($this->procDir . '/' . $entry)) {
                    $survivors[] = (int) $entry;
                }

                continue;
            }
            $close = strrpos($stat, ')');
            $state = $close === false ? '' : substr($stat, $close + 2, 1);
            if ($state !== 'Z' && $state !== 'X') {
                $survivors[] = (int) $entry;
            }
        }

        return $survivors;
    }
}
