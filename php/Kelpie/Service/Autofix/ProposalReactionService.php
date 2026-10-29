<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\Enum\ProjectMode;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Service\Project\ProjectModeService;
use Psr\Log\LoggerInterface as Logger;
use Throwable;
use Ubix\Service\JsonService;

/**
 * Acts on a thumbs-up or thumbs-down that the webhook accepted for one of Kelpie's proposals
 *
 * The webhook has already checked who reacted. Here the proposal is read again: it must still be
 * `proposed`. A thumbs-up hands the stored edits to the push round as a round of one, which holds them to the
 * same rules as any push (files the MR changes, a moved head, the gate, one commit, never forced). A
 * thumbs-down only marks the proposal declined and leaves the thread open.
 *
 * @see \Kelpie\Tests\Service\Autofix\ProposalReactionServiceTest PHPUnit test case
 */
final class ProposalReactionService
{
    /**
     * Constructor
     *
     * @param Logger              $logger   Logger
     * @param ReplyService        $replies  Posts the replies
     * @param PushRoundService    $round    Pushes the fix
     * @param ThreadOutcomeReader $reader   The record of what Kelpie proposed
     * @param ThreadOutcomeWriter $outcomes Where the proposal's fate is written
     * @param JsonService         $json     JSON service
     * @param ProjectModeService  $modes    What Kelpie may do on the project: a push needs `push`
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ReplyService $replies,
        private readonly PushRoundService $round,
        private readonly ThreadOutcomeReader $reader,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly JsonService $json,
        private readonly ProjectModeService $modes,
    ) {
    }

    /**
     * Act on one reaction
     *
     * @param string $project      The project ID
     * @param string $projectPath  The project path with namespace
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The thread
     * @param bool   $apply        True for a thumbs-up, false for a thumbs-down
     * @param int    $jobId        The job this runs under
     *
     * @return ?string Null when handled (even if refused with a reply); why, when the worker should try again
     */
    public function run(string $project, string $projectPath, int $mrIid, string $discussionId, bool $apply, int $jobId): ?string
    {
        $row = $this->reader->getByThread($projectPath, $mrIid, $discussionId);
        if ($row === null || $row->outcome !== ThreadOutcome::Proposed) {
            $this->logger->info('Proposal reaction: nothing open to act on', ['discussion_id' => $discussionId, 'mr_iid' => $mrIid]);

            return null;
        }

        if (!$apply) {
            $this->outcomes->setOutcome($projectPath, $mrIid, $discussionId, ThreadOutcome::Declined);
            $this->replies->notice($project, $mrIid, $discussionId, 'Understood, I have dropped this proposal and changed nothing. The thread is still open; reply with `Triage: <what to do instead>` to have me look again.', 'declined');

            return null;
        }

        // The one path that pushes: only a project in push mode, whatever the job says. Checked here, next to the push.
        if (!$this->modes->allows($projectPath, ProjectMode::Push)) {
            $this->logger->info('Proposal reaction: project is not in push mode, nothing pushed', ['project' => $projectPath, 'mr_iid' => $mrIid]);
            $this->replies->notice($project, $mrIid, $discussionId, 'This project is not set to let me push, so I did not apply the proposal. The diff above is still there to apply by hand.', 'not-pushed');

            return null;
        }

        $edits = $this->edits($row->editsJson);
        if ($edits === []) {
            $this->outcomes->setOutcome($projectPath, $mrIid, $discussionId, ThreadOutcome::Failed);
            $this->replies->notice($project, $mrIid, $discussionId, 'The stored edits could not be read, so I cannot apply them. Reply with `Triage:` to have me propose again.', 'failed');

            return null;
        }

        // A round of one: the same rules as any push (files the MR changes, the gate, one commit).
        return $this->round->run($project, $projectPath, $mrIid, $row->headSha, [['discussionId' => $discussionId, 'edits' => $edits, 'summary' => $row->reason]], $jobId);
    }

    /**
     * The stored edits
     *
     * @param string $editsJson The JSON the proposal stored
     *
     * @return list<ProposedEdit> Empty when the JSON is not a list of edits
     */
    private function edits(string $editsJson): array
    {
        try {
            $rows = $this->json->decode($editsJson);
        } catch (Throwable $e) {
            $this->logger->warning('Proposal reaction: stored edits are not JSON', ['error' => $e->getMessage()]);

            return [];
        }

        $edits = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['file'] ?? null) || !is_string($row['old'] ?? null) || !is_string($row['new'] ?? null)) {
                return [];
            }

            $edits[] = new ProposedEdit($row['file'], $row['old'], $row['new']);
        }

        return $edits;
    }
}
