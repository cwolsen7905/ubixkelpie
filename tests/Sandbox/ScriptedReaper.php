<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\ProcessReaperInterface as ProcessReaper;

/**
 * A reaper for tests: answers with the given results in order ('' once they run out), and counts its calls
 *
 * With a pid file it also kills the process named in it (SIGKILL to that one PID only), and reports it as a survivor
 * when it is still alive afterwards: the stand-in for "kill everything but the server" outside a container.
 */
final class ScriptedReaper implements ProcessReaper
{
    private int $calls = 0;

    /**
     * Constructor
     *
     * @param string[] $results One per call: '' for a clear container, else why it is not
     * @param string   $pidFile A file a run writes the PID of a process it left behind into; '' for none
     * @param bool     $kill    Whether to kill that process (false: leave it, so it survives)
     */
    public function __construct(
        private array $results = [],
        private readonly string $pidFile = '',
        private readonly bool $kill = true,
    ) {
    }

    /**
     * How many times it was asked to reap
     *
     * @return int
     */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * {@inheritDoc}
     */
    public function reap(): string
    {
        $this->calls++;
        $result = array_shift($this->results) ?? '';
        if ($result !== '' || $this->pidFile === '' || !is_file($this->pidFile)) {
            return $result;
        }

        $pid = (int) trim((string) file_get_contents($this->pidFile));
        if ($pid > 1 && $this->kill) {
            posix_kill($pid, 9);
            $waited = 0;
            while ($waited < 50 && $this->alive($pid)) {
                usleep(20000);
                $waited++;
            }
        }

        return $pid > 1 && $this->alive($pid) ? 'processes survived the reset: ' . $pid : '';
    }

    /**
     * Whether a process exists and is not a zombie (dead, waiting for a parent that may never reap it)
     *
     * @param int $pid The process
     *
     * @return bool
     */
    private function alive(int $pid): bool
    {
        $stat  = is_readable('/proc/' . $pid . '/stat') ? (string) file_get_contents('/proc/' . $pid . '/stat') : '';
        $close = strrpos($stat, ')');

        return posix_kill($pid, 0) && ($close === false || substr($stat, $close + 2, 1) !== 'Z');
    }
}
