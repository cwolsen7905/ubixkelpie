<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;

/**
 * An in-memory outcome table for tests: one row per thread, replaced by a new run, as in the SQL repository
 */
final class InMemoryOutcomeStore implements ThreadOutcomeReader, ThreadOutcomeWriter
{
    /**
     * The rows by "project/mr/discussion"
     *
     * @var array<string, ThreadOutcomeRecord>
     */
    private array $rows = [];

    /**
     * Every row written, in order, to see what a run recorded even if a later run replaced it
     *
     * @var list<ThreadOutcomeRecord>
     */
    private array $written = [];

    /**
     * @return list<ThreadOutcomeRecord>
     */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * {@inheritDoc}
     */
    public function getByThread(string $projectPath, int $mrIid, string $discussionId): ?ThreadOutcomeRecord
    {
        return $this->rows[$projectPath . '/' . $mrIid . '/' . $discussionId] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function record(ThreadOutcomeRecord $record): void
    {
        $this->rows[$record->projectPath . '/' . $record->mrIid . '/' . $record->discussionId] = $record;
        $this->written[]                                                                       = $record;
    }

    /**
     * {@inheritDoc}
     */
    public function setOutcome(string $projectPath, int $mrIid, string $discussionId, ThreadOutcome $outcome): void
    {
        $row = $this->getByThread($projectPath, $mrIid, $discussionId);
        if ($row === null) {
            return;
        }

        $this->rows[$projectPath . '/' . $mrIid . '/' . $discussionId] = new ThreadOutcomeRecord(
            $row->projectPath,
            $row->mrIid,
            $row->discussionId,
            $row->headSha,
            $row->verdict,
            $row->mode,
            $outcome,
            $row->reason,
            $row->triageModel,
            $row->proposeModel,
            $row->reviewFamily,
            $row->promptTokens,
            $row->completionTokens,
            $row->costUsd,
            $row->proposalNoteId,
            $row->editsJson,
            $row->diff,
        );
    }
}
