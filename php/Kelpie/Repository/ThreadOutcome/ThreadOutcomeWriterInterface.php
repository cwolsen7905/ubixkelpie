<?php

declare(strict_types=1);

namespace Kelpie\Repository\ThreadOutcome;

use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;

/**
 * Writes the record of what Kelpie did about each thread
 */
interface ThreadOutcomeWriterInterface
{
    /**
     * Record a run on a thread; a thread that has a row has it replaced by this run
     *
     * @param ThreadOutcomeRecord $record What the run did
     *
     * @return void
     */
    public function record(ThreadOutcomeRecord $record): void;

    /**
     * Change what became of a thread's last proposal (a reaction, a revert, a reopened thread)
     *
     * @param string        $projectPath  The project path
     * @param int           $mrIid        The MR IID
     * @param string        $discussionId The discussion ID
     * @param ThreadOutcome $outcome      The new outcome
     *
     * @return void
     */
    public function setOutcome(string $projectPath, int $mrIid, string $discussionId, ThreadOutcome $outcome): void;
}
