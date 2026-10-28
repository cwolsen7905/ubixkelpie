<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Psr\Log\LoggerInterface as Logger;

/**
 * Whether a unified diff changed the code near a line of the file's old side
 *
 * Reads only the diff text: a removed line counts at its own old line number and an added line at the old
 * line it was put before. No git, no I/O.
 *
 * @see \Kelpie\Tests\Service\Autofix\DiffLineServiceTest PHPUnit test case
 */
final class DiffLineService
{
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
     * Whether the diff changed a line within `$radius` of `$line` on the old side
     *
     * @param string $diff   One file's unified diff, hunks only
     * @param int    $line   The old-side line number
     * @param int    $radius How many lines either side still count as near
     *
     * @return bool
     */
    public function touches(string $diff, int $line, int $radius = 3): bool
    {
        $old = 0;

        foreach (explode("\n", $diff) as $row) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+\d+(?:,\d+)? @@/', $row, $m) === 1) {
                $old = (int) $m[1];
                continue;
            }
            if ($old === 0 || $row === '' || $row[0] === '\\') {
                continue;
            }

            $mark = $row[0];
            if ($mark === ' ') {
                $old++;
                continue;
            }
            if ($mark !== '-' && $mark !== '+') {
                continue;
            }
            if (abs($old - $line) <= $radius) {
                $this->logger->debug('Diff changed code near the line', ['line' => $line, 'old_line' => $old]);

                return true;
            }
            if ($mark === '-') {
                $old++;
            }
        }

        return false;
    }
}
