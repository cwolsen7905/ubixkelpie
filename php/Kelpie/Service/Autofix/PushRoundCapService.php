<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Psr\Log\LoggerInterface as Logger;

/**
 * How big one automatic push round may be: how many threads, and how many changed lines
 *
 * The same figures the safety policy holds a single fix to. A round over either is not pushed at all: its fixes stay
 * proposals, each open for a thumbs-up one at a time.
 *
 * @see \Kelpie\Tests\Service\Autofix\PushRoundCapServiceTest PHPUnit test case
 */
final class PushRoundCapService
{
    public const int MAX_THREADS = 5;

    public const int MAX_LINES = 80;

    /**
     * Constructor
     *
     * @param Logger $logger     Logger
     * @param int    $maxThreads Most threads one round may fix
     * @param int    $maxLines   Most changed lines one round may push
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly int $maxThreads = self::MAX_THREADS,
        private readonly int $maxLines = self::MAX_LINES,
    ) {
    }

    /**
     * Whether a round of this many threads is over the cap
     *
     * @param int $threads The threads in the round
     *
     * @return bool
     */
    public function threadsOver(int $threads): bool
    {
        $over = $threads > $this->maxThreads;
        if ($over) {
            $this->logger->info('Push round over the thread cap', ['threads' => $threads, 'max' => $this->maxThreads]);
        }

        return $over;
    }

    /**
     * Whether a round changing this many lines is over the cap
     *
     * @param int $lines The changed lines of the round, added and removed
     *
     * @return bool
     */
    public function linesOver(int $lines): bool
    {
        $over = $lines > $this->maxLines;
        if ($over) {
            $this->logger->info('Push round over the line cap', ['lines' => $lines, 'max' => $this->maxLines]);
        }

        return $over;
    }

    /**
     * What to tell a thread when its round was too big
     *
     * @param int  $threads The threads in the round
     * @param ?int $lines   The changed lines, when they were measured
     *
     * @return string
     */
    public function notice(int $threads, ?int $lines = null): string
    {
        $what = $lines === null ? 'this round has ' . $threads . ' fixes and I push at most ' . $this->maxThreads . ' in one commit' : 'this round changes ' . $lines . ' lines and I push at most ' . $this->maxLines . ' in one commit';

        return 'Over the round cap: ' . $what . ', so I pushed nothing. This proposal is still open: give it a 👍 to have me push it on its own.';
    }
}
