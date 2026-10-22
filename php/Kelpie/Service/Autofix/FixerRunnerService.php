<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\ChangeSet;
use Kelpie\DataTransferObject\Completion;
use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\DataTransferObject\ThreadResult;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\FixOutcome;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageStatus;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageService;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Propose mode for one thread: triage it, then reply with a diff, a dismissal, a deferral or a reason it needs a person
 *
 * Off unless `$enabled` (`KELPIE_AUTOFIX_ENABLED=true`). For a thread triaged `fix` the propose model returns exact-text
 * edits, Kelpie applies them in a scratch checkout at the head it read, and the reply carries the diff git writes. A
 * dismissal or a deferral is a reply with the reason. Nothing is pushed and nothing is resolved by a proposal. A head
 * that moved, a closed MR or a thread already answered supersedes the job (this job only, the one the worker holds).
 * Every thread it answers gets one row in the outcome table.
 *
 * @see \Kelpie\Tests\Service\Autofix\FixerRunnerServiceTest PHPUnit test case
 */
final class FixerRunnerService
{
    private const array PROTECTED_BRANCHES = ['dev', 'staging', 'main', 'master'];

    private const string MODE = 'propose';

    /**
     * Constructor
     *
     * @param Logger              $logger        Logger
     * @param bool                $enabled       KELPIE_AUTOFIX_ENABLED
     * @param DiscussionWriter    $writer        The GitLab writer
     * @param ClaimService        $claimService  The claim marker
     * @param ReplyService        $replyService  The reply and label step
     * @param TriageService       $triageService Classifies the thread
     * @param ProposeEditsService $propose       Asks the propose model for edits
     * @param EditApplyService    $editApplier   Applies the edits to the checkout
     * @param TriageStatusService $status        The status labels and the summary note
     * @param SafetyPolicy        $safetyPolicy  The hard limits, applied to the real diff
     * @param LlmFamilyService    $families      The review's family, for the record
     * @param Checkout            $checkout      The sandbox checkout
     * @param AutofixJobReader    $jobReader     The job reader
     * @param AutofixJobWriter    $jobWriter     The job writer
     * @param ThreadOutcomeWriter $outcomes      The outcome record
     * @param string              $remoteUrl     The git URL of the project
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $enabled,
        private readonly DiscussionWriter $writer,
        private readonly ClaimService $claimService,
        private readonly ReplyService $replyService,
        private readonly TriageService $triageService,
        private readonly ProposeEditsService $propose,
        private readonly EditApplyService $editApplier,
        private readonly TriageStatusService $status,
        private readonly SafetyPolicy $safetyPolicy,
        private readonly LlmFamilyService $families,
        private readonly Checkout $checkout,
        private readonly AutofixJobReader $jobReader,
        private readonly AutofixJobWriter $jobWriter,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly string $remoteUrl,
    ) {
    }

    /**
     * Run propose mode for one thread
     *
     * @param string $project     The project ID
     * @param string $projectPath The project path with namespace (the job key)
     * @param int    $mrIid       The MR IID
     * @param Thread $thread      The thread
     * @param string $sha         The head sha the thread was read at; the checkout is made here
     * @param int    $jobId       The job for this MR and thread, held by the caller (pending, or running under its lease)
     * @param string $steering    What the author or a maintainer asked for with "Triage:", empty when nothing
     *
     * @return FixOutcome
     */
    public function run(string $project, string $projectPath, int $mrIid, Thread $thread, string $sha, int $jobId, string $steering = ''): FixOutcome
    {
        if (!$this->enabled) {
            return FixOutcome::Disabled;
        }

        $id = $thread->discussionId;

        $mr = $this->writer->mergeRequest($project, $mrIid);
        if ($mr === null || !$mr->open || $mr->sourceProjectId !== $mr->targetProjectId || in_array($mr->sourceBranch, self::PROTECTED_BRANCHES, true)) {
            $this->jobWriter->supersedeJob($jobId);

            return FixOutcome::Skipped;
        }
        if ($mr->sha !== $sha) {
            $this->jobWriter->supersedeJob($jobId);

            return FixOutcome::HeadMoved;
        }

        if ($this->status->skipReason($mr) !== null) {
            $this->jobWriter->supersedeJob($jobId);

            return FixOutcome::Skipped;
        }
        if ($this->status->isClaimed($mr)) {
            return FixOutcome::Skipped; // Left pending: another run is on this merge request
        }

        $notes = $this->writer->notes($project, $mrIid, $id);
        if ($notes === null) {
            return FixOutcome::Failed;
        }
        if ($this->replyService->isAnswered($notes, $id)) {
            $this->jobWriter->supersedeJob($jobId);

            return FixOutcome::Skipped;
        }

        $claim = $this->claimService->claim($project, $mrIid, $id, $sha);
        if ($claim instanceof ResponseOutcome) {
            return FixOutcome::Skipped; // Left pending: whoever holds the claim is on it
        }

        $this->jobWriter->markRunning($jobId);
        $statusRun = $this->status->start($project, $mrIid, [$thread]);
        $class     = null;
        $result    = null;

        try {
            $verdict = $this->triageService->triage($thread, null, $steering);
            $class   = $verdict->class->value;

            [$outcome, $result, $record] = match ($verdict->class) {
                TriageClass::Fix                          => $this->proposeFix($project, $projectPath, $mrIid, $thread, $sha, $mr->sourceBranch, $mr->targetBranch, $mr->draft, $verdict, $steering),
                TriageClass::NotAnIssue, TriageClass::Defer => $this->proposeNoChange($project, $projectPath, $mrIid, $thread, $sha, $verdict),
                default                                   => $this->answer($project, $projectPath, $mrIid, $thread, $sha, $verdict),
            };
            $this->outcomes->record($record);
        } catch (Throwable $e) {
            $this->logger->error('Autofix runner crashed', ['error' => $e->getMessage(), 'mr_iid' => $mrIid]);
            $outcome = FixOutcome::Failed;
        } finally {
            $this->checkout->close();
            $this->claimService->release($project, $mrIid, $id, $claim, $sha);
            $this->status->finish($project, $mrIid, $statusRun, $result === null ? [] : [$id => $result]);
        }

        $done = in_array($outcome, [FixOutcome::Proposed, FixOutcome::NotAFix], true);
        $this->jobWriter->markFinished($jobId, $done ? 'done' : 'failed', $class);
        $this->logger->info('Autofix run finished', ['outcome' => $outcome->value, 'mr_iid' => $mrIid, 'job_id' => $jobId]);

        return $outcome;
    }

    /**
     * A thread triaged `fix`: edits, applied in the scratch checkout, and the diff as the proposal
     *
     * @param string        $project      The project ID
     * @param string        $projectPath  The project path
     * @param int           $mrIid        The MR IID
     * @param Thread        $thread       The thread
     * @param string        $sha          The triaged sha
     * @param string        $sourceBranch The MR source branch
     * @param string        $targetBranch The MR target branch
     * @param bool          $draft        Whether the MR is a draft
     * @param TriageVerdict $verdict      Triage's verdict
     * @param string        $steering     What the author or a maintainer asked for with "Triage:", empty when nothing
     *
     * @return array{0: FixOutcome, 1: ?ThreadResult, 2: ThreadOutcomeRecord}
     */
    private function proposeFix(string $project, string $projectPath, int $mrIid, Thread $thread, string $sha, string $sourceBranch, string $targetBranch, bool $draft, TriageVerdict $verdict, string $steering = ''): array
    {
        $id = $thread->discussionId;

        if (!$this->checkout->open($this->remoteUrl, $sourceBranch, $sha)) {
            return [FixOutcome::Failed, null, $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Failed, 'The scratch checkout could not be made.')];
        }

        $edits = $this->propose->propose($thread, $this->checkout->directory(), $verdict->reason, $steering);
        if ($edits->failure !== '') {
            return $this->refused($project, $projectPath, $mrIid, $id, $sha, $verdict, FixOutcome::EditsRejected, $edits->failure, $edits->completion);
        }

        $rejected = $this->editApplier->apply($this->checkout->directory(), $edits->edits);
        if ($rejected !== null) {
            return $this->refused($project, $projectPath, $mrIid, $id, $sha, $verdict, FixOutcome::EditsRejected, 'The edits I got did not apply exactly: ' . $rejected, $edits->completion);
        }

        $changes = $this->checkout->stageAndMeasure();
        if ($changes === null) {
            return [FixOutcome::Failed, null, $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Failed, 'git could not measure the edits.', $edits->completion)];
        }
        if ($changes->paths === []) {
            return $this->refused($project, $projectPath, $mrIid, $id, $sha, $verdict, FixOutcome::NoChange, 'The edits changed nothing.', $edits->completion);
        }

        $unsafe = $this->unsafe($projectPath, $mrIid, $id, $changes, $targetBranch, $draft);
        if ($unsafe !== null) {
            return $this->refused($project, $projectPath, $mrIid, $id, $sha, $verdict, FixOutcome::Unsafe, $unsafe, $edits->completion);
        }

        $diff = $this->checkout->stagedDiff();
        if ($diff === null || trim($diff) === '') {
            return [FixOutcome::Failed, null, $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Failed, 'git wrote no diff.', $edits->completion)];
        }
        if (preg_match('/<!--\s*kelpie:/i', $diff) === 1) {
            return $this->refused($project, $projectPath, $mrIid, $id, $sha, $verdict, FixOutcome::Unsafe, 'The change contains text that looks like one of my own markers.', $edits->completion);
        }

        // Last look before the reply: a proposal made against a head that has moved would not apply.
        $now = $this->writer->mergeRequest($project, $mrIid);
        if ($now === null || $now->sha !== $sha || $now->sourceBranch !== $sourceBranch) {
            return [FixOutcome::HeadMoved, null, $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Failed, 'The head moved before the proposal was posted.', $edits->completion)];
        }

        $summary = $edits->summary === '' ? $verdict->reason : $edits->summary;
        $note    = $this->replyService->propose($project, $mrIid, $id, TriageClass::Fix, $sha, $summary, $diff);
        if ($note === null) {
            return [FixOutcome::Failed, null, $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Failed, 'The proposal could not be posted.', $edits->completion, diff: $diff)];
        }

        return [
            FixOutcome::Proposed,
            new ThreadResult(TriageStatus::Proposed, 'proposed a change to `' . implode('`, `', $changes->paths) . '`'),
            $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Proposed, $summary, $edits->completion, $note, $edits->editsJson, $diff),
        ];
    }

    /**
     * A thread triaged `not_an_issue` or `defer`: the reason, as a proposal to dismiss or to defer
     *
     * @param string        $project     The project ID
     * @param string        $projectPath The project path
     * @param int           $mrIid       The MR IID
     * @param Thread        $thread      The thread
     * @param string        $sha         The triaged sha
     * @param TriageVerdict $verdict     Triage's verdict
     *
     * @return array{0: FixOutcome, 1: ?ThreadResult, 2: ThreadOutcomeRecord}
     */
    private function proposeNoChange(string $project, string $projectPath, int $mrIid, Thread $thread, string $sha, TriageVerdict $verdict): array
    {
        $id   = $thread->discussionId;
        $note = $this->replyService->propose($project, $mrIid, $id, $verdict->class, $sha, $verdict->reason);
        if ($note === null) {
            return [FixOutcome::Failed, null, $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Failed, 'The proposal could not be posted.')];
        }

        $result = $this->status->resultFor($verdict, ResponseOutcome::Replied);

        return [FixOutcome::Proposed, $result, $this->record($projectPath, $mrIid, $id, $sha, $verdict, ThreadOutcome::Proposed, $verdict->reason, null, $note)];
    }

    /**
     * A thread that needs a person, or is already fixed: the existing reply, no proposal
     *
     * @param string        $project     The project ID
     * @param string        $projectPath The project path
     * @param int           $mrIid       The MR IID
     * @param Thread        $thread      The thread
     * @param string        $sha         The triaged sha
     * @param TriageVerdict $verdict     Triage's verdict
     *
     * @return array{0: FixOutcome, 1: ?ThreadResult, 2: ThreadOutcomeRecord}
     */
    private function answer(string $project, string $projectPath, int $mrIid, Thread $thread, string $sha, TriageVerdict $verdict): array
    {
        $replied = $this->replyService->respond($project, $mrIid, $thread->discussionId, $verdict);
        $outcome = $replied === ResponseOutcome::Failed ? ThreadOutcome::Failed : ThreadOutcome::Answered;

        return [FixOutcome::NotAFix, $this->status->resultFor($verdict, $replied), $this->record($projectPath, $mrIid, $thread->discussionId, $sha, $verdict, $outcome, $verdict->reason)];
    }

    /**
     * Tell the thread why no proposal could be made, and record it
     *
     * @param string        $project     The project ID
     * @param string        $projectPath The project path
     * @param int           $mrIid       The MR IID
     * @param string        $id          The discussion ID
     * @param string        $sha         The triaged sha
     * @param TriageVerdict $verdict     Triage's verdict
     * @param FixOutcome    $outcome     Why, as an outcome
     * @param string        $reason      Why, for the person
     * @param ?Completion   $completion  The propose model call, when there was one
     *
     * @return array{0: FixOutcome, 1: ?ThreadResult, 2: ThreadOutcomeRecord}
     */
    private function refused(string $project, string $projectPath, int $mrIid, string $id, string $sha, TriageVerdict $verdict, FixOutcome $outcome, string $reason, ?Completion $completion): array
    {
        $needsHuman = new TriageVerdict(TriageClass::NeedsHuman, $reason);
        $replied    = $this->replyService->respond($project, $mrIid, $id, $needsHuman);

        return [
            $outcome,
            $this->status->resultFor($needsHuman, $replied),
            $this->record($projectPath, $mrIid, $id, $sha, $verdict, $replied === ResponseOutcome::Failed ? ThreadOutcome::Failed : ThreadOutcome::Answered, $reason, $completion),
        ];
    }

    /**
     * The outcome row for this run, with the models and the cost of both calls
     *
     * @param string        $projectPath The project path
     * @param int           $mrIid       The MR IID
     * @param string        $id          The discussion ID
     * @param string        $sha         The triaged sha
     * @param TriageVerdict $verdict     Triage's verdict
     * @param ThreadOutcome $outcome     What came of it
     * @param string        $reason      Why, or the summary
     * @param ?Completion   $propose     The propose model call, when there was one
     * @param ?int          $note        The proposal note
     * @param string        $editsJson   The edits as JSON
     * @param string        $diff        The diff posted
     *
     * @return ThreadOutcomeRecord
     */
    private function record(string $projectPath, int $mrIid, string $id, string $sha, TriageVerdict $verdict, ThreadOutcome $outcome, string $reason, ?Completion $propose = null, ?int $note = null, string $editsJson = '', string $diff = ''): ThreadOutcomeRecord
    {
        $calls  = array_values(array_filter([$verdict->completion, $propose]));
        $cost   = null;
        $prompt = 0;
        $answer = 0;
        foreach ($calls as $call) {
            $prompt += $call->promptTokens;
            $answer += $call->completionTokens;
            $cost    = $call->costUsd === null ? $cost : ($cost ?? 0.0) + $call->costUsd;
        }

        return new ThreadOutcomeRecord(
            $projectPath,
            $mrIid,
            $id,
            $sha,
            $verdict->class->value,
            self::MODE,
            $outcome,
            $reason,
            $verdict->completion->model ?? '',
            $propose->model ?? '',
            $this->families->reviewFamily(),
            $prompt,
            $answer,
            $cost,
            $note,
            $editsJson,
            $diff,
        );
    }

    /**
     * The safety policy over the real diff
     *
     * @param string    $projectPath  The project path
     * @param int       $mrIid        The MR IID
     * @param string    $discussionId The discussion ID
     * @param ChangeSet $changes      The measured diff
     * @param string    $targetBranch The MR target branch
     * @param bool      $draft        Whether the MR is a draft
     *
     * @return ?string The reason it is unsafe, null when within the limits
     */
    private function unsafe(string $projectPath, int $mrIid, string $discussionId, ChangeSet $changes, string $targetBranch, bool $draft): ?string
    {
        $proposal = new FixProposal(
            $changes->changedLines,
            $changes->paths,
            $changes->deletesOrRenamesFiles,
            $changes->weakensTests,
            $this->jobReader->countAttemptsForThread($projectPath, $mrIid, $discussionId),
            $this->jobReader->countFixCommitsForMr($projectPath, $mrIid),
            $targetBranch,
            $draft,
        );

        $checked = $this->safetyPolicy->apply(new TriageVerdict(TriageClass::Fix, ''), $proposal);

        return $checked->class === TriageClass::Fix ? null : $checked->reason;
    }
}
