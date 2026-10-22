<?php

declare(strict_types=1);

namespace Kelpie\Repository\ThreadOutcome;

use Kelpie\DataTransferObject\ThreadOutcomeRecord;

/**
 * Reads the record of what Kelpie did about each thread
 */
interface ThreadOutcomeReaderInterface
{
    /**
     * The record of one thread
     *
     * @param string $projectPath  The project path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     *
     * @return ?ThreadOutcomeRecord Null when Kelpie never touched the thread
     */
    public function getByThread(string $projectPath, int $mrIid, string $discussionId): ?ThreadOutcomeRecord;

    /**
     * The record whose proposal is a given note
     *
     * @param string $projectPath The project path
     * @param int    $mrIid       The MR IID
     * @param int    $noteId      The note carrying the proposal
     *
     * @return ?ThreadOutcomeRecord Null when no proposal of Kelpie's is that note
     */
    public function getByProposalNote(string $projectPath, int $mrIid, int $noteId): ?ThreadOutcomeRecord;
}
