<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Psr\Log\LoggerInterface as Logger;

/**
 * Says whether every place a gate run can write to is wiped between runs, from the container's mount table
 *
 * The reset empties the work directory and the clean directories. Anything else a run could write to would carry files
 * from one run into the next, so the server refuses to start while one exists. Read from /proc/self/mountinfo, so a
 * mount added to the manifest later cannot be forgotten in the clean list:
 *
 * - the root filesystem mounted read-write is refused outright (the pod runs with a read-only root);
 * - every other directory mount that is read-write and writable by this process must be the work directory, a clean
 *   directory, or inside one. /proc and /sys are not storage and are left out. File mounts (/etc/hosts and the like)
 *   cannot hold a file a run drops, and are left out too.
 *
 * Like the reset, it applies only when the server is PID 1 (the gate pod). Elsewhere (a developer's machine, a test)
 * the first reset fails and the server stops after one run anyway, so there is nothing for it to protect.
 *
 * @see \Kelpie\Tests\Sandbox\WritableMountCheckTest PHPUnit test case
 */
final class WritableMountCheck
{
    /**
     * Constructor
     *
     * @param Logger $logger    Logger
     * @param string $mountInfo The mount table
     * @param int    $pid       This process's PID as the container sees it; 0 reads getmypid()
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $mountInfo = '/proc/self/mountinfo',
        private readonly int $pid = 0,
    ) {
    }

    /**
     * Why the wiped directories do not cover every writable mount, or empty when they do
     *
     * @param string   $workDir   The work directory
     * @param string[] $cleanDirs The clean directories
     *
     * @return string
     */
    public function uncovered(string $workDir, array $cleanDirs): string
    {
        if (($this->pid ?: (int) getmypid()) !== 1) {
            $this->logger->debug('Gate server is not PID 1: writable mounts not checked');

            return '';
        }

        $table = is_readable($this->mountInfo) ? file($this->mountInfo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($table === false) {
            return 'the mount table (' . $this->mountInfo . ') cannot be read';
        }

        $covered   = array_map([$this, 'normal'], [$workDir, ...$cleanDirs]);
        $uncovered = [];
        foreach ($table as $line) {
            // Fields: id, parent, major:minor, root, mount point, options, [optional fields], -, type, source, super options
            $fields = explode(' ', $line);
            if (count($fields) < 6) {
                return 'the mount table has a line it cannot read';
            }
            $mountPoint = $this->unescape($fields[4]);
            $readWrite  = in_array('rw', explode(',', $fields[5]), true);
            if (!$readWrite || $this->isUnder($mountPoint, ['/proc', '/sys'])) {
                continue;
            }
            if ($mountPoint === '/') {
                return 'the root filesystem is mounted read-write; the gate pod needs it read-only';
            }
            if (is_dir($mountPoint) && is_writable($mountPoint) && !$this->isUnder($this->normal($mountPoint), $covered)) {
                $uncovered[] = $mountPoint;
            }
        }

        return $uncovered === [] ? '' : 'writable mounts not wiped between runs (add them to KELPIE_GATE_CLEAN_DIRS): ' . implode(', ', $uncovered);
    }

    /**
     * Whether a path is one of the directories or inside one
     *
     * @param string   $path        The path
     * @param string[] $directories The directories
     *
     * @return bool
     */
    private function isUnder(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            if ($path === $directory || str_starts_with($path, rtrim($directory, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path without a trailing slash, through its real location when it exists
     *
     * @param string $path The path
     *
     * @return string
     */
    private function normal(string $path): string
    {
        $real = realpath($path);

        return $real === false ? (rtrim($path, '/') ?: '/') : $real;
    }

    /**
     * Undo the mount table's octal escapes (\040 for a space, \011, \012, \134)
     *
     * @param string $field The field
     *
     * @return string
     */
    private function unescape(string $field): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static function (array $match): string {
            return chr(max(0, min(255, (int) octdec($match[1]))));
        }, $field);
    }
}
