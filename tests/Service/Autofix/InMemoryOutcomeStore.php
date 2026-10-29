<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use RuntimeException;

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
     * When each row was recorded, by the same key
     *
     * @var array<string, DateTimeImmutable>
     */
    private array $recordedAt = [];

    private bool $failNext = false;

    /**
     * Every row written, in order, to see what a run recorded even if a later run replaced it
     *
     * @var list<ThreadOutcomeRecord>
     */
    private array $written = [];

    /**
     * Make the next `record()` throw, to play a database that fails
     *
     * @return void
     */
    public function failNextRecord(): void
    {
        $this->failNext = true;
    }

    /**
     * Pretend a row was last written at a time, to see a day boundary
     *
     * @param string            $projectPath  The project path
     * @param int               $mrIid        The MR IID
     * @param string            $discussionId The discussion ID
     * @param DateTimeImmutable $at           When it was written
     *
     * @return void
     */
    public function backdate(string $projectPath, int $mrIid, string $discussionId, DateTimeImmutable $at): void
    {
        $this->recordedAt[$projectPath . '/' . $mrIid . '/' . $discussionId] = $at;
    }

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
    public function getByProposalNote(string $projectPath, int $mrIid, int $noteId): ?ThreadOutcomeRecord
    {
        foreach ($this->rows as $row) {
            if ($row->projectPath === $projectPath && $row->mrIid === $mrIid && $row->proposalNoteId === $noteId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function costToday(?string $projectPath): float
    {
        $sum = 0.0;
        foreach ($this->rows as $key => $row) {
            if (($projectPath === null || $row->projectPath === $projectPath) && $this->recordedAt[$key] >= new DateTimeImmutable('today')) {
                $sum += $row->costUsd ?? 0.0;
            }
        }

        return $sum;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException After `failNextRecord()`
     */
    public function record(ThreadOutcomeRecord $record): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('The outcome table is down.');
        }

        $key   = $record->projectPath . '/' . $record->mrIid . '/' . $record->discussionId;
        $old   = $this->rows[$key] ?? null;
        $today = $old !== null && ($this->recordedAt[$key] ?? new DateTimeImmutable()) >= new DateTimeImmutable('today');
        if ($today && $old !== null && $old->costUsd !== null && $record->costUsd !== null) {
            $record = $this->withCost($record, $old->costUsd + $record->costUsd);
        } elseif ($today && $old !== null && $record->costUsd === null) {
            $record = $this->withCost($record, $old->costUsd);
        }

        $this->rows[$key]       = $record;
        $this->recordedAt[$key] = new DateTimeImmutable();
        $this->written[]        = $record;
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

    /**
     * The record with another cost
     *
     * @param ThreadOutcomeRecord $row  The record
     * @param ?float              $cost The cost to carry
     *
     * @return ThreadOutcomeRecord
     */
    private function withCost(ThreadOutcomeRecord $row, ?float $cost): ThreadOutcomeRecord
    {
        return new ThreadOutcomeRecord(
            $row->projectPath,
            $row->mrIid,
            $row->discussionId,
            $row->headSha,
            $row->verdict,
            $row->mode,
            $row->outcome,
            $row->reason,
            $row->triageModel,
            $row->proposeModel,
            $row->reviewFamily,
            $row->promptTokens,
            $row->completionTokens,
            $cost,
            $row->proposalNoteId,
            $row->editsJson,
            $row->diff,
        );
    }
}
