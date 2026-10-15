<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\Autofix\ClaimService;
use Kelpie\Autofix\FixerAgentInterface as FixerAgent;
use Kelpie\Autofix\GateRunner;
use Kelpie\Autofix\ReplyService;
use Kelpie\DataTransferObject\ChangeSet;
use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\FixOutcome;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Triage\SafetyPolicy;
use Kelpie\Triage\TriageService;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Fix one `fix`-triaged thread: checkout at the triaged sha, agent, gate, one commit, non-force push
 *
 * Off unless `$enabled` (`KELPIE_AUTOFIX_ENABLED=true`). The push goes to the MR's own source branch
 * only, never a fork, never `dev`/`staging`/`main`. It is skipped if the branch head is no longer the
 * sha the thread was triaged at, checked just before the push as well as at the start. Failure paths
 * answer on the thread (design section 5) and push nothing. After a push it replies with the commit
 * link (section 4) and leaves the thread open.
 *
 * @see \Kelpie\Tests\Service\Autofix\FixerRunnerServiceTest PHPUnit test case
 */
final class FixerRunnerService
{
    private const int MAX_RUNS = 2;

    private const array PROTECTED_BRANCHES = ['dev', 'staging', 'main', 'master'];

    /**
     * Constructor
     *
     * @param Logger           $logger        Logger
     * @param bool             $enabled       KELPIE_AUTOFIX_ENABLED
     * @param DiscussionWriter $writer        The GitLab writer
     * @param ClaimService     $claimService  The claim marker
     * @param ReplyService     $replyService  The reply and label step
     * @param TriageService    $triageService Classifies the thread
     * @param SafetyPolicy     $safetyPolicy  The hard limits, applied to the real diff
     * @param FixerAgent       $agent         Edits the checkout
     * @param GateRunner       $gateRunner    The repo's gate
     * @param Checkout         $checkout      The sandbox checkout
     * @param AutofixJobReader $jobReader     The job reader
     * @param AutofixJobWriter $jobWriter     The job writer
     * @param string           $remoteUrl     The git URL of the project
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $enabled,
        private readonly DiscussionWriter $writer,
        private readonly ClaimService $claimService,
        private readonly ReplyService $replyService,
        private readonly TriageService $triageService,
        private readonly SafetyPolicy $safetyPolicy,
        private readonly FixerAgent $agent,
        private readonly GateRunner $gateRunner,
        private readonly Checkout $checkout,
        private readonly AutofixJobReader $jobReader,
        private readonly AutofixJobWriter $jobWriter,
        private readonly string $remoteUrl,
    ) {
    }

    /**
     * Run the fixer for one thread
     *
     * @param string $project     The project ID
     * @param string $projectPath The project path with namespace (the job key)
     * @param int    $mrIid       The MR IID
     * @param Thread $thread      The thread
     * @param string $sha         The head sha the thread was read at; the checkout is made here
     * @param int    $jobId       The pending job for this MR and thread
     *
     * @return FixOutcome
     */
    public function run(string $project, string $projectPath, int $mrIid, Thread $thread, string $sha, int $jobId): FixOutcome
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
        $labelled = $this->writer->addLabel($project, $mrIid, ClaimService::LABEL);
        $class    = null;
        $commit   = null;

        try {
            $verdict = $this->triageService->triage($thread);
            $class   = $verdict->class->value;

            if ($verdict->class !== TriageClass::Fix) {
                $this->replyService->respond($project, $mrIid, $id, $verdict);
                $outcome = FixOutcome::NotAFix;
            } else {
                [$outcome, $commit] = $this->fix($project, $projectPath, $mrIid, $thread, $sha, $mr->sourceBranch, $mr->targetBranch, $mr->draft);

                if ($outcome === FixOutcome::Pushed && $commit !== null) {
                    $this->announce($project, $mrIid, $id, $commit, $mr->webUrl, $verdict->reason);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('Autofix runner crashed', ['error' => $e->getMessage(), 'mr_iid' => $mrIid]);
            $outcome = FixOutcome::Failed;
        } finally {
            $this->checkout->close();
            $this->claimService->release($project, $mrIid, $id, $claim, $sha);
            if ($labelled) {
                $this->writer->removeLabel($project, $mrIid, ClaimService::LABEL);
            }
        }

        $done = in_array($outcome, [FixOutcome::Pushed, FixOutcome::NotAFix], true);
        $this->jobWriter->markFinished($jobId, $done ? 'done' : 'failed', $class, $commit);
        $this->logger->info('Autofix run finished', ['outcome' => $outcome->value, 'mr_iid' => $mrIid, 'job_id' => $jobId]);

        return $outcome;
    }

    /**
     * Reply on the thread with the commit link. The push stands whether or not the reply lands.
     *
     * @param string $project      The project ID
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     * @param string $commit       The pushed commit
     * @param string $mrUrl        The MR's web URL
     * @param string $summary      The triage reason, used as the summary
     *
     * @return void
     */
    private function announce(string $project, int $mrIid, string $discussionId, string $commit, string $mrUrl, string $summary): void
    {
        $base = (string) preg_replace('#/-/merge_requests/\d+/?$#', '', $mrUrl);
        $url  = $base === $mrUrl || $base === '' ? '' : $base . '/-/commit/' . $commit;

        if ($this->replyService->fixed($project, $mrIid, $discussionId, $commit, $url, $summary) !== ResponseOutcome::Replied) {
            $this->logger->warning('Autofix fix reply could not be posted', ['commit' => $commit, 'mr_iid' => $mrIid]);
        }
    }

    /**
     * The fix path for a thread triaged `fix`
     *
     * @param string $project      The project ID
     * @param string $projectPath  The project path
     * @param int    $mrIid        The MR IID
     * @param Thread $thread       The thread
     * @param string $sha          The triaged sha
     * @param string $sourceBranch The MR source branch
     * @param string $targetBranch The MR target branch
     * @param bool   $draft        Whether the MR is a draft
     *
     * @return array{0: FixOutcome, 1: ?string} The outcome and the pushed commit
     */
    private function fix(string $project, string $projectPath, int $mrIid, Thread $thread, string $sha, string $sourceBranch, string $targetBranch, bool $draft): array
    {
        $id = $thread->discussionId;

        if (!$this->checkout->open($this->remoteUrl, $sourceBranch, $sha)) {
            return [FixOutcome::Failed, null];
        }

        $failure = null;
        $run     = 0;
        while (true) {
            $run++;
            if (!$this->agent->fix($this->checkout->directory(), $thread, $failure)) {
                return [FixOutcome::AgentFailed, null];
            }

            $changes = $this->checkout->stageAndMeasure();
            if ($changes === null) {
                return [FixOutcome::Failed, null];
            }
            if ($changes->paths === []) {
                $this->needsHuman($project, $mrIid, $id, 'The agent made no change.');

                return [FixOutcome::NoChange, null];
            }

            $unsafe = $this->unsafe($projectPath, $mrIid, $id, $changes, $targetBranch, $draft);
            if ($unsafe !== null) {
                $this->needsHuman($project, $mrIid, $id, $unsafe);

                return [FixOutcome::Unsafe, null];
            }

            $gate = $this->gateRunner->run($this->checkout->directory());
            if ($gate->passed) {
                break;
            }
            $failure = $gate->output;

            if ($run >= self::MAX_RUNS) {
                $this->needsHuman($project, $mrIid, $id, 'The fix I tried did not pass `' . $gate->failedStep . '`.');

                return [FixOutcome::GateFailed, null];
            }
        }

        $message = sprintf("fix: address review thread\n\nKelpie-Autofix: thread=%s sha=%s", $thread->discussionId, $sha);
        $commit  = $this->checkout->commit($message);
        if ($commit === null) {
            return [FixOutcome::Failed, null];
        }

        // Last look before the push: if the branch moved past the triaged sha, push nothing.
        $now = $this->writer->mergeRequest($project, $mrIid);
        if ($now === null || $now->sha !== $sha || $now->sourceBranch !== $sourceBranch) {
            return [FixOutcome::HeadMoved, null];
        }

        if (!$this->checkout->push($sourceBranch)) {
            return [FixOutcome::PushRejected, null];
        }

        return [FixOutcome::Pushed, $commit];
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

    /**
     * Answer the thread on the not-safe path
     *
     * @param string $project      The project ID
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     * @param string $reason       Why
     *
     * @return void
     */
    private function needsHuman(string $project, int $mrIid, string $discussionId, string $reason): void
    {
        $this->replyService->respond($project, $mrIid, $discussionId, new TriageVerdict(TriageClass::NeedsHuman, $reason));
    }
}
