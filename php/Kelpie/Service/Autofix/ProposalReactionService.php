<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\Enum\ProjectMode;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Service\Project\ProjectModeService;
use Psr\Log\LoggerInterface as Logger;
use Throwable;
use Ubix\Service\JsonService;

/**
 * Acts on a thumbs-up or thumbs-down that the webhook accepted for one of Kelpie's proposals
 *
 * The webhook has already checked who reacted. Here the proposal is read again: it must still be
 * `proposed`, and for a thumbs-up the MR head must still be the one it was made at, else the reply says
 * so and nothing is set. A thumbs-up applies the stored edits in a scratch checkout, runs the gate,
 * commits and pushes (never forced); a red gate pushes nothing. A thumbs-down only marks the proposal
 * declined and leaves the thread open.
 *
 * @see \Kelpie\Tests\Service\Autofix\ProposalReactionServiceTest PHPUnit test case
 */
final class ProposalReactionService
{
    private const array PROTECTED_BRANCHES = ['dev', 'staging', 'main', 'master'];

    /**
     * Constructor
     *
     * @param Logger              $logger    Logger
     * @param DiscussionWriter    $writer    GitLab, as the bot
     * @param ReplyService        $replies   Posts the replies
     * @param EditApplyService    $editApply Applies the stored edits
     * @param Checkout            $checkout  The scratch checkout and the push
     * @param GateRunner          $gate      Runs the project gate on the result
     * @param ThreadOutcomeReader $reader    The record of what Kelpie proposed
     * @param ThreadOutcomeWriter $outcomes  Where the proposal's fate is written
     * @param AutofixJobWriter    $jobs      Where the fix commit is noted on the job
     * @param JsonService         $json      JSON service
     * @param ProjectModeService  $modes     What Kelpie may do on the project: a push needs `push`
     * @param string              $remoteUrl The project's git remote
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
        private readonly ReplyService $replies,
        private readonly EditApplyService $editApply,
        private readonly Checkout $checkout,
        private readonly GateRunner $gate,
        private readonly ThreadOutcomeReader $reader,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly AutofixJobWriter $jobs,
        private readonly JsonService $json,
        private readonly ProjectModeService $modes,
        private readonly string $remoteUrl,
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

        try {
            return $this->apply($project, $projectPath, $mrIid, $discussionId, $row->headSha, $row->editsJson, $row->reason, $jobId);
        } finally {
            $this->checkout->close();
        }
    }

    /**
     * Push the stored edits
     *
     * @param string $project      The project ID
     * @param string $projectPath  The project path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The thread
     * @param string $sha          The head the proposal was made at
     * @param string $editsJson    The stored edits
     * @param string $summary      The proposal's reason, for the commit subject
     * @param int    $jobId        The job
     *
     * @return ?string Null when handled, why when to retry
     */
    private function apply(string $project, string $projectPath, int $mrIid, string $discussionId, string $sha, string $editsJson, string $summary, int $jobId): ?string
    {
        $mr = $this->writer->mergeRequest($project, $mrIid);
        if ($mr === null) {
            return 'could not read the merge request';
        }
        if (!$mr->open || $mr->sourceProjectId !== $mr->targetProjectId || in_array($mr->sourceBranch, self::PROTECTED_BRANCHES, true)) {
            $this->logger->info('Proposal reaction: merge request is closed, from a fork or from a protected branch', ['mr_iid' => $mrIid]);

            return null;
        }
        if ($mr->sha !== $sha) {
            $this->stale($project, $mrIid, $discussionId);

            return null;
        }

        $edits = $this->edits($editsJson);
        if ($edits === []) {
            $this->fail($project, $projectPath, $mrIid, $discussionId, 'The stored edits could not be read, so I cannot apply them. Reply with `Triage:` to have me propose again.');

            return null;
        }
        if (!$this->checkout->open($this->remoteUrl, $mr->sourceBranch, $sha)) {
            return 'the scratch checkout could not be made';
        }

        $rejected = $this->editApply->apply($this->checkout->directory(), $edits);
        if ($rejected !== null) {
            $this->fail($project, $projectPath, $mrIid, $discussionId, 'The edits no longer apply exactly: ' . $rejected . ' Nothing was pushed.');

            return null;
        }
        $changes = $this->checkout->stageAndMeasure();
        if ($changes === null) {
            return 'git could not stage the edits';
        }
        if ($changes->paths === []) {
            $this->fail($project, $projectPath, $mrIid, $discussionId, 'The edits change nothing now. Nothing was pushed.');

            return null;
        }

        try {
            $gate = $this->gate->run($this->checkout->directory());
        } catch (Throwable $e) {
            $this->logger->error('Proposal reaction: gate crashed', ['error' => $e->getMessage(), 'mr_iid' => $mrIid]);

            return 'the gate could not run';
        }
        if (!$gate->passed) {
            $this->fail($project, $projectPath, $mrIid, $discussionId, 'The gate is red with this change (' . ($gate->failedStep ?? 'a step') . '), so I did not push it.');

            return null;
        }

        $commit = $this->checkout->commit($this->message($summary, $discussionId));
        if ($commit === null) {
            return 'git could not commit';
        }

        // Last look before the push: the branch must still be at the head the proposal was made at.
        $now = $this->writer->mergeRequest($project, $mrIid);
        if ($now === null || $now->sha !== $sha || $now->sourceBranch !== $mr->sourceBranch) {
            $this->stale($project, $mrIid, $discussionId);

            return null;
        }
        if (!$this->checkout->push($mr->sourceBranch)) {
            return 'the push was rejected';
        }

        $this->outcomes->setOutcome($projectPath, $mrIid, $discussionId, ThreadOutcome::Applied);
        $this->jobs->markFinished($jobId, 'done', 'fix', $commit);

        // Pushed, gate green: resolve only a thread Kelpie opened itself, never a person's.
        $notes = $this->writer->notes($project, $mrIid, $discussionId);
        $this->replies->fixed($project, $mrIid, $discussionId, $commit, '', $summary, $notes !== null && $this->replies->openedByKelpie($notes, $discussionId));

        return null;
    }

    /**
     * Tell the thread the head moved, and set nothing
     *
     * @param string $project      The project ID
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The thread
     *
     * @return void
     */
    private function stale(string $project, int $mrIid, string $discussionId): void
    {
        $this->replies->notice($project, $mrIid, $discussionId, 'Stale: the branch has moved since I made this proposal, so I did not apply it. Reply with `Triage:` to have me look again at the new head.', 'stale');
    }

    /**
     * Say why it was not applied and mark the proposal failed
     *
     * @param string $project      The project ID
     * @param string $projectPath  The project path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The thread
     * @param string $text         What to tell the thread
     *
     * @return void
     */
    private function fail(string $project, string $projectPath, int $mrIid, string $discussionId, string $text): void
    {
        $this->outcomes->setOutcome($projectPath, $mrIid, $discussionId, ThreadOutcome::Failed);
        $this->replies->notice($project, $mrIid, $discussionId, $text, 'failed');
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

    /**
     * The commit message: a `fix(review)` subject from the proposal's reason, the thread, and the loop-guard trailer
     *
     * @param string $summary      The proposal's reason
     * @param string $discussionId The thread
     *
     * @return string
     */
    private function message(string $summary, string $discussionId): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', str_replace(['<!--', '-->'], '', $summary)));
        $line = rtrim(mb_substr($line, 0, 60), ' .');
        $line = $line === '' ? 'apply the proposed change' : lcfirst($line);

        return 'fix(review): ' . $line . "\n\nApplies the change Kelpie proposed in this review thread, approved with a thumbs-up.\n\nThread: " . $discussionId . "\nKelpie-Autofix: propose\n";
    }
}
