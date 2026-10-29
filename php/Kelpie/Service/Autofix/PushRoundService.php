<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Sandbox\GateRunner;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Pushes the fixes of one review round to an MR's source branch: one commit, one plain push
 *
 * Every fix is applied in one scratch checkout at the head it was made at. A fix that edits a file the MR does not
 * already change is left out, and so is one whose edits no longer apply; the rest go through the project gate together.
 * A green gate gives one `fix(review)` commit with a `Thread:` line for each thread, pushed once and never forced. Each
 * fixed thread then gets a `Fixed:` reply with the commit, and is resolved only when Kelpie opened it. A red gate, a
 * moved head or a rejected push leaves every thread as it was.
 *
 * @see \Kelpie\Tests\Service\Autofix\PushRoundServiceTest PHPUnit test case
 */
final class PushRoundService
{
    private const array PROTECTED_BRANCHES = ['dev', 'staging', 'main', 'master'];

    /**
     * Constructor
     *
     * @param Logger              $logger    Logger
     * @param DiscussionWriter    $writer    GitLab, as the bot
     * @param ReplyService        $replies   Posts the replies
     * @param EditApplyService    $editApply Applies the edits
     * @param Checkout            $checkout  The scratch checkout and the push
     * @param GateRunner          $gate      Runs the project gate on the result
     * @param ThreadOutcomeWriter $outcomes  Where each proposal's fate is written
     * @param AutofixJobWriter    $jobs      Where the fix commit is noted on the job
     * @param string              $remoteUrl The project's git remote
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
        private readonly ReplyService $replies,
        private readonly EditApplyService $editApply,
        private readonly Checkout $checkout,
        private readonly GateRunner $gate,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly AutofixJobWriter $jobs,
        private readonly string $remoteUrl,
    ) {
    }

    /**
     * Push a round
     *
     * @param string                                                                        $project     The project ID
     * @param string                                                                        $projectPath The project path with namespace
     * @param int                                                                           $mrIid       The MR IID
     * @param string                                                                        $sha         The head every fix was made at
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>}> $fixes       The fixes, one per thread
     * @param int                                                                           $jobId       The job this runs under
     *
     * @return ?string Null when handled (even if refused with a reply); why, when the worker should try again
     */
    public function run(string $project, string $projectPath, int $mrIid, string $sha, array $fixes, int $jobId): ?string
    {
        if ($fixes === []) {
            return null;
        }

        try {
            return $this->push($project, $projectPath, $mrIid, $sha, $fixes, $jobId);
        } finally {
            $this->checkout->close();
        }
    }

    /**
     * @param string                                                                        $project     The project ID
     * @param string                                                                        $projectPath The project path
     * @param int                                                                           $mrIid       The MR IID
     * @param string                                                                        $sha         The head the fixes were made at
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>}> $fixes       The fixes
     * @param int                                                                           $jobId       The job
     *
     * @return ?string Null when handled, why when to retry
     */
    private function push(string $project, string $projectPath, int $mrIid, string $sha, array $fixes, int $jobId): ?string
    {
        $mr = $this->writer->mergeRequest($project, $mrIid);
        if ($mr === null) {
            return 'could not read the merge request';
        }
        if (!$mr->open || $mr->sourceProjectId !== $mr->targetProjectId || in_array($mr->sourceBranch, self::PROTECTED_BRANCHES, true)) {
            $this->logger->info('Push round: merge request is closed, from a fork or from a protected branch', ['mr_iid' => $mrIid]);

            return null;
        }
        if ($mr->sha !== $sha) {
            $this->stale($project, $mrIid, $fixes);

            return null;
        }

        $changed = $this->writer->changedPaths($project, $mrIid);
        if ($changed === null) {
            return 'could not read the files the merge request changes';
        }
        if (!$this->checkout->open($this->remoteUrl, $mr->sourceBranch, $sha)) {
            return 'the scratch checkout could not be made';
        }

        $pushing = [];
        foreach ($fixes as $fix) {
            $outside = $this->outside($changed, $fix['edits']);
            if ($outside !== null) {
                $this->fail($project, $projectPath, $mrIid, $fix['discussionId'], 'This fix edits `' . $outside . '`, which this merge request does not change, so I did not push it. Nothing was changed; apply the diff above by hand if you want it.');

                continue;
            }

            $rejected = $this->editApply->apply($this->checkout->directory(), $fix['edits']);
            if ($rejected !== null) {
                $this->fail($project, $projectPath, $mrIid, $fix['discussionId'], 'The edits no longer apply exactly: ' . $rejected . ' Nothing was pushed.');

                continue;
            }

            $pushing[] = $fix;
        }
        if ($pushing === []) {
            return null;
        }

        $changes = $this->checkout->stageAndMeasure();
        if ($changes === null) {
            return 'git could not stage the edits';
        }
        if ($changes->paths === []) {
            $this->failAll($project, $projectPath, $mrIid, $pushing, 'The edits change nothing now. Nothing was pushed.');

            return null;
        }

        try {
            $gate = $this->gate->run($this->checkout->directory());
        } catch (Throwable $e) {
            $this->logger->error('Push round: gate crashed', ['error' => $e->getMessage(), 'mr_iid' => $mrIid]);

            return 'the gate could not run';
        }
        if (!$gate->passed) {
            $this->failAll($project, $projectPath, $mrIid, $pushing, 'The gate is red with this change (' . ($gate->failedStep ?? 'a step') . '), so I did not push it.');

            return null;
        }

        $commit = $this->checkout->commit($this->message($pushing));
        if ($commit === null) {
            return 'git could not commit';
        }

        // Last look before the push: the branch must still be at the head the fixes were made at.
        $now = $this->writer->mergeRequest($project, $mrIid);
        if ($now === null || $now->sha !== $sha || $now->sourceBranch !== $mr->sourceBranch) {
            $this->stale($project, $mrIid, $pushing);

            return null;
        }
        if (!$this->checkout->push($mr->sourceBranch)) {
            return 'the push was rejected';
        }

        $this->jobs->markFinished($jobId, 'done', 'fix', $commit);

        $url = $this->commitUrl($mr->webUrl, $commit);
        foreach ($pushing as $fix) {
            $id = $fix['discussionId'];
            $this->outcomes->setOutcome($projectPath, $mrIid, $id, ThreadOutcome::Applied);

            // Pushed, gate green: resolve only a thread Kelpie opened itself, never a person's.
            $notes = $this->writer->notes($project, $mrIid, $id);
            $this->replies->fixed($project, $mrIid, $id, $commit, $url, $fix['summary'], $notes !== null && $this->replies->openedByKelpie($notes, $id));
        }

        return null;
    }

    /**
     * The first edited file the MR does not already change
     *
     * @param string[]       $changed The paths the MR changes
     * @param ProposedEdit[] $edits   One fix's edits
     *
     * @return ?string Null when every edit is in a changed file
     */
    private function outside(array $changed, array $edits): ?string
    {
        foreach ($edits as $edit) {
            if (!in_array(preg_replace('#^(\./)+#', '', $edit->file), $changed, true)) {
                return $edit->file;
            }
        }

        return null;
    }

    /**
     * Tell each thread the head moved, and set nothing
     *
     * @param string                                                                        $project The project ID
     * @param int                                                                           $mrIid   The MR IID
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>}> $fixes   The fixes
     *
     * @return void
     */
    private function stale(string $project, int $mrIid, array $fixes): void
    {
        foreach ($fixes as $fix) {
            $this->replies->notice($project, $mrIid, $fix['discussionId'], 'Stale: the branch has moved since I made this proposal, so I did not apply it. Reply with `Triage:` to have me look again at the new head.', 'stale');
        }
    }

    /**
     * Say why a fix was not pushed and mark its proposal failed
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
     * Fail every fix of the round with one reason
     *
     * @param string                                                                        $project     The project ID
     * @param string                                                                        $projectPath The project path
     * @param int                                                                           $mrIid       The MR IID
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>}> $fixes       The fixes
     * @param string                                                                        $text        What to tell each thread
     *
     * @return void
     */
    private function failAll(string $project, string $projectPath, int $mrIid, array $fixes, string $text): void
    {
        foreach ($fixes as $fix) {
            $this->fail($project, $projectPath, $mrIid, $fix['discussionId'], $text);
        }
    }

    /**
     * The commit's web URL, worked out from the MR's
     *
     * @param string $mrUrl  The MR's web URL
     * @param string $commit The commit sha
     *
     * @return string Empty when the MR's URL is not the usual shape
     */
    private function commitUrl(string $mrUrl, string $commit): string
    {
        return preg_match('#^(.+)/-/merge_requests/\d+$#', $mrUrl, $m) === 1 ? $m[1] . '/-/commit/' . $commit : '';
    }

    /**
     * The commit message: a `fix(review)` subject, a `Thread:` line for each thread, and the loop-guard trailer
     *
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>}> $fixes The fixes pushed
     *
     * @return string
     */
    private function message(array $fixes): string
    {
        if (count($fixes) === 1) {
            $line = trim((string) preg_replace('/\s+/', ' ', str_replace(['<!--', '-->'], '', $fixes[0]['summary'])));
            $line = rtrim(mb_substr($line, 0, 60), ' .');
            $line = $line === '' ? 'apply the proposed change' : lcfirst($line);
        } else {
            $line = 'address ' . count($fixes) . ' review threads';
        }

        $threads = '';
        foreach ($fixes as $fix) {
            $threads .= 'Thread: ' . $fix['discussionId'] . "\n";
        }

        return 'fix(review): ' . $line . "\n\nApplies the changes Kelpie proposed in these review threads.\n\n" . $threads . "Kelpie-Autofix: propose\n";
    }
}
