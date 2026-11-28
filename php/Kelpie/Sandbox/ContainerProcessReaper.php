<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Closure;
use Kelpie\Sandbox\ProcessReaperInterface as ProcessReaper;
use Psr\Log\LoggerInterface as Logger;

/**
 * Kills every other process in the gate pod's container, from PID 1, and checks /proc that none is left
 *
 * The gate server is the container's PID 1 (the manifest's `command` starts `php` with no shell in front). From
 * PID 1, `kill(-1, SIGKILL)` reaches every process in the container's PID namespace except PID 1 itself, so whatever a
 * run left behind (a background process, a daemon that detached) is gone. The kill is repeated until /proc lists
 * nothing but this process and zombies (dead, waiting to be reaped: they run nothing), or the attempts run out.
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

    /**
     * Constructor
     *
     * @param Logger   $logger  Logger
     * @param string   $procDir Where the process list is read
     * @param int      $pid     This process's PID as the container sees it; 0 reads getmypid()
     * @param ?Closure $kill    Sends SIGKILL to every other process (`Closure(): bool`); null is posix_kill(-1, SIGKILL)
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $procDir = '/proc',
        private readonly int $pid = 0,
        private readonly ?Closure $kill = null,
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
        if ($this->kill === null && !function_exists('posix_kill')) {
            return 'posix_kill is not available';
        }

        $survivors = [];
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $this->killAll();
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
     * @return void
     */
    private function killAll(): void
    {
        $sent = $this->kill === null ? posix_kill(-1, self::SIGKILL) : ($this->kill)();
        if ($sent === false) {
            // ESRCH: there was nothing to signal. Whatever it was, the check after decides.
            $this->logger->debug('Gate pod kill found nothing to signal');
        }
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
            $stat  = is_readable($this->procDir . '/' . $entry . '/stat') ? (string) file_get_contents($this->procDir . '/' . $entry . '/stat') : '';
            $close = strrpos($stat, ')');
            $state = $close === false ? '' : substr($stat, $close + 2, 1);
            if ($stat !== '' && $state !== 'Z' && $state !== 'X') {
                $survivors[] = (int) $entry;
            }
        }

        return $survivors;
    }
}
