<?php

declare(strict_types=1);

namespace Kelpie\Repository\ThreadOutcome;

use Kelpie\DataTransferObject\SqlRepository\ThreadOutcomeOptions;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;

/**
 * SQL-backed thread outcome repository
 *
 * @see \Kelpie\Tests\Repository\ThreadOutcome\ThreadOutcomeSqlRepositoryTest PHPUnit test case
 */
final class ThreadOutcomeSqlRepository implements ThreadOutcomeReader, ThreadOutcomeWriter
{
    /**
     * Constructor
     *
     * @param Logger     $logger     The Monolog logger
     * @param SqlService $sqlService The SQL service
     */
    public function __construct(
        private Logger $logger,
        private SqlService $sqlService,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getByThread(string $projectPath, int $mrIid, string $discussionId): ?ThreadOutcomeRecord
    {
        return $this->query(new ThreadOutcomeOptions($projectPath, $mrIid, $discussionId))[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getByProposalNote(string $projectPath, int $mrIid, int $noteId): ?ThreadOutcomeRecord
    {
        return $this->query(new ThreadOutcomeOptions($projectPath, $mrIid, proposalNoteId: $noteId))[0] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * A run on a thread that has a row replaces everything but the cost. The cost adds up over one day (by the
     * database's clock, the one that stamps `date_last_updated`), so the
     * spend caps count every run on the thread; a row last written on an earlier day starts again from this run's
     * cost, so yesterday's spend is not carried into today.
     */
    public function record(ThreadOutcomeRecord $record): void
    {
        $sql = 'INSERT INTO ubixkelpie.thread_outcomes
                    (project_path, mr_iid, discussion_id, head_sha, verdict, mode, outcome, reason, triage_model, propose_model,
                     review_family, prompt_tokens, completion_tokens, cost_usd, proposal_note_id, edits_json, diff)
                VALUES
                    (:project_path, :mr_iid, :discussion_id, :head_sha, :verdict, :mode, :outcome, :reason, :triage_model, :propose_model,
                     :review_family, :prompt_tokens, :completion_tokens, :cost_usd, :proposal_note_id, :edits_json, :diff)
                ON DUPLICATE KEY UPDATE
                    head_sha = VALUES(head_sha), verdict = VALUES(verdict), mode = VALUES(mode), outcome = VALUES(outcome),
                    reason = VALUES(reason), triage_model = VALUES(triage_model), propose_model = VALUES(propose_model),
                    review_family = VALUES(review_family), prompt_tokens = VALUES(prompt_tokens),
                    completion_tokens = VALUES(completion_tokens),
                    cost_usd = IF(VALUES(cost_usd) IS NULL, IF(date_last_updated >= CURDATE(), cost_usd, NULL),
                                  IF(date_last_updated >= CURDATE(), COALESCE(cost_usd, 0), 0) + VALUES(cost_usd)),
                    proposal_note_id = VALUES(proposal_note_id), edits_json = VALUES(edits_json), diff = VALUES(diff)';

        $this->sqlService->query($sql, [
            'completion_tokens' => $record->completionTokens,
            'cost_usd'          => $record->costUsd,
            'diff'              => $record->diff === '' ? null : $record->diff,
            'discussion_id'     => $record->discussionId,
            'edits_json'        => $record->editsJson === '' ? null : $record->editsJson,
            'head_sha'          => $record->headSha,
            'mode'              => $record->mode,
            'mr_iid'            => $record->mrIid,
            'outcome'           => $record->outcome->value,
            'project_path'      => $record->projectPath,
            'prompt_tokens'     => $record->promptTokens,
            'proposal_note_id'  => $record->proposalNoteId,
            'propose_model'     => $record->proposeModel,
            'reason'            => mb_substr($record->reason, 0, 500),
            'review_family'     => $record->reviewFamily,
            'triage_model'      => $record->triageModel,
            'verdict'           => $record->verdict,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function setOutcome(string $projectPath, int $mrIid, string $discussionId, ThreadOutcome $outcome): void
    {
        // The column is set to itself so a reaction does not move the row into today's spend
        $sql = 'UPDATE ubixkelpie.thread_outcomes SET outcome = :outcome, date_last_updated = date_last_updated
                WHERE project_path = :project_path AND mr_iid = :mr_iid AND discussion_id = :discussion_id';

        $this->sqlService->query($sql, ['discussion_id' => $discussionId, 'mr_iid' => $mrIid, 'outcome' => $outcome->value, 'project_path' => $projectPath]);
    }

    /**
     * {@inheritDoc}
     */
    public function costToday(?string $projectPath): float
    {
        $sql        = 'SELECT COALESCE(SUM(cost_usd), 0) AS spent FROM ubixkelpie.thread_outcomes WHERE date_last_updated >= CURDATE()';
        $parameters = [];

        if ($projectPath !== null) {
            $sql .= ' AND project_path = :project_path';

            $parameters['project_path'] = $projectPath;
        }

        foreach ($this->sqlService->getRows($sql, $parameters) as $row) {
            return (float) $row['spent'];
        }

        return 0.0;
    }

    /**
     * Query thread outcomes
     *
     * @param ThreadOutcomeOptions $options DTO of options to generate the query
     *
     * @return ThreadOutcomeRecord[]
     */
    private function query(ThreadOutcomeOptions $options): array
    {
        $sql        = 'SELECT project_path, mr_iid, discussion_id, head_sha, verdict, mode, outcome, reason, triage_model, propose_model,
                       review_family, prompt_tokens, completion_tokens, cost_usd, proposal_note_id, edits_json, diff
                FROM ubixkelpie.thread_outcomes
                WHERE project_path = :project_path AND mr_iid = :mr_iid';
        $parameters = ['mr_iid' => $options->mrIid, 'project_path' => $options->projectPath];

        if ($options->discussionId !== null) {
            $sql .= ' AND discussion_id = :discussion_id';

            $parameters['discussion_id'] = $options->discussionId;
        }
        if ($options->proposalNoteId !== null) {
            $sql .= ' AND proposal_note_id = :proposal_note_id';

            $parameters['proposal_note_id'] = $options->proposalNoteId;
        }

        $records = [];
        foreach ($this->sqlService->getRows($sql, $parameters) as $row) {
            $outcome = ThreadOutcome::tryFrom((string) $row['outcome']);
            if ($outcome === null) {
                $this->logger->warning('Unknown thread outcome, row skipped', ['outcome' => $row['outcome']]);
                continue;
            }

            $records[] = new ThreadOutcomeRecord(
                (string) $row['project_path'],
                (int) $row['mr_iid'],
                (string) $row['discussion_id'],
                (string) $row['head_sha'],
                (string) $row['verdict'],
                (string) $row['mode'],
                $outcome,
                (string) $row['reason'],
                (string) $row['triage_model'],
                (string) $row['propose_model'],
                (string) $row['review_family'],
                (int) $row['prompt_tokens'],
                (int) $row['completion_tokens'],
                $row['cost_usd'] === null ? null : (float) $row['cost_usd'],
                $row['proposal_note_id'] === null ? null : (int) $row['proposal_note_id'],
                (string) $row['edits_json'],
                (string) $row['diff'],
            );
        }

        return $records;
    }
}
