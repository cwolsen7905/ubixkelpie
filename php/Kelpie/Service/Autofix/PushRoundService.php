<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\Autofix\Attribution;
use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Pushes the fixes of one review round to an MR's source branch: one commit, one plain push
 *
 * Every fix is applied in one scratch checkout at the head it was made at. A fix may edit the files the MR already
 * changes, test files, and files its thread names by path (a finding that says "the brief in `internal/x.go` was not
 * updated"); a fix that edits any other file is left out, and so is one whose edits no longer apply. The rest go
 * through the project's gate together (its `gate_command`, `make check`, or the PHP gate; `ci` leaves it to the merge
 * request pipeline, and the replies say so).
 * A green gate gives one `fix(review)` commit with a `Thread:` line for each thread, pushed once and never forced. Each
 * fixed thread then gets a `Fixed:` reply with the commit, and is resolved only when Kelpie opened it. A red gate, a
 * moved head or a rejected push leaves every thread as it was. An automatic round also has a size cap: one that changes
 * too many lines is not pushed, and its threads are told.
 *
 * @see \Kelpie\Tests\Service\Autofix\PushRoundServiceTest PHPUnit test case
 */
final class PushRoundService
{
    private const array PROTECTED_BRANCHES = ['dev', 'staging', 'main', 'master'];

    /**
     * Test files by the usual conventions: a tests folder, Go's `_test.go`, PHPUnit's `Test.php`, JS/TS `.test.`/`.spec.`, pytest
     */
    private const string TEST_FILE = '#(^|/)(tests?|__tests__|spec)/|_test\.go$|Test\.php$|\.(test|spec)\.[cm]?[jt]sx?$|(^|/)test_[^/]*\.py$|_test\.py$#';

    /**
     * Constructor
     *
     * @param Logger              $logger      Logger
     * @param DiscussionWriter    $writer      GitLab, as the bot
     * @param ReplyService        $replies     Posts the replies
     * @param EditApplyService    $editApply   Applies the edits
     * @param Checkout            $checkout    The scratch checkout and the push
     * @param IssueGateService    $gate        Runs the project's gate on the result
     * @param ThreadOutcomeWriter $outcomes    Where each proposal's fate is written
     * @param AutofixJobWriter    $jobs        Where the fix commit is noted on the job
     * @param string              $remoteUrl   The project's git remote
     * @param ?string             $gateCommand The project's own gate command, null for the default
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
        private readonly ReplyService $replies,
        private readonly EditApplyService $editApply,
        private readonly Checkout $checkout,
        private readonly IssueGateService $gate,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly AutofixJobWriter $jobs,
        private readonly string $remoteUrl,
        private readonly ?string $gateCommand = null,
    ) {
    }

    /**
     * Push a round
     *
     * @param string                                                                                        $project     The project ID
     * @param string                                                                                        $projectPath The project path with namespace
     * @param int                                                                                           $mrIid       The MR IID
     * @param string                                                                                        $sha         The head every fix was made at
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>, model?: string}> $fixes       The fixes, one per thread; a fix with no edits is one whose stored edits could not be read
     * @param int                                                                                           $jobId       The job this runs under
     * @param ThreadOutcome                                                                                 $outcome     What each pushed thread is recorded as: `Applied` after a thumbs-up, `Pushed` for an automatic round
     * @param ?PushRoundCapService                                                                          $cap         The round's size cap, for an automatic round; null holds nothing to one (a person asked for it)
     *
     * @return ?string Null when handled (even if refused with a reply); why, when the worker should try again
     */
    public function run(string $project, string $projectPath, int $mrIid, string $sha, array $fixes, int $jobId, ThreadOutcome $outcome = ThreadOutcome::Applied, ?PushRoundCapService $cap = null): ?string
    {
        if ($fixes === []) {
            return null;
        }

        try {
            return $this->push($project, $projectPath, $mrIid, $sha, $fixes, $jobId, $outcome, $cap);
        } finally {
            $this->checkout->close();
        }
    }

    /**
     * Tell a thread its round was too big, once: the proposal stays open and the notice is not repeated by a later round
     *
     * @param string $project      The project ID
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The thread
     * @param string $text         What to say
     *
     * @return void
     */
    public function overCap(string $project, int $mrIid, string $discussionId, string $text): void
    {
        $notes = $this->writer->notes($project, $mrIid, $discussionId);
        if ($notes !== null) {
            foreach ($notes as $note) {
                if (str_contains($note->body, 'class=over-cap')) {
                    return;
                }
            }
        }

        $this->replies->notice($project, $mrIid, $discussionId, $text, 'over-cap');
    }

    /**
     * @param string                                                                                        $project     The project ID
     * @param string                                                                                        $projectPath The project path
     * @param int                                                                                           $mrIid       The MR IID
     * @param string                                                                                        $sha         The head the fixes were made at
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>, model?: string}> $fixes       The fixes
     * @param int                                                                                           $jobId       The job
     * @param ThreadOutcome                                                                                 $outcome     What each pushed thread is recorded as
     * @param ?PushRoundCapService                                                                          $cap         The round's size cap, when it has one
     *
     * @return ?string Null when handled, why when to retry
     */
    private function push(string $project, string $projectPath, int $mrIid, string $sha, array $fixes, int $jobId, ThreadOutcome $outcome, ?PushRoundCapService $cap): ?string
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
        if ($changed === false) {
            $this->failAll($project, $projectPath, $mrIid, $fixes, 'This merge request changes too many files for me to check which ones, so I did not push. Nothing was changed.');

            return null;
        }
        if (!$this->checkout->open($this->remoteUrl, $mr->sourceBranch, $sha)) {
            return 'the scratch checkout could not be made';
        }

        $pushing = [];
        $root    = realpath($this->checkout->directory());
        foreach ($fixes as $fix) {
            if ($fix['edits'] === []) {
                $this->fail($project, $projectPath, $mrIid, $fix['discussionId'], 'The stored edits could not be read, so I cannot apply them. Reply with `Triage:` to have me propose again.');

                continue;
            }

            $outside = $root === false ? $fix['edits'][0]->file : $this->outside($root, $changed, $fix['edits'], $this->openingNote($project, $mrIid, $fix['discussionId']));
            if ($outside !== null) {
                $this->fail($project, $projectPath, $mrIid, $fix['discussionId'], 'This fix edits `' . $outside . '`, which this merge request does not change, is not a test and is not named in the thread, so I did not push it. Nothing was changed; apply the diff above by hand if you want it.');

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
        if ($cap !== null && $cap->linesOver($changes->changedLines)) {
            // Too big for me to push unasked: the proposals stay open, each for its own thumbs-up
            foreach ($pushing as $fix) {
                $this->overCap($project, $mrIid, $fix['discussionId'], $cap->notice(count($pushing), $changes->changedLines));
            }

            return null;
        }

        try {
            $gate = $this->gate->run($this->checkout->directory(), $this->gateCommand);
        } catch (Throwable $e) {
            $this->logger->error('Push round: gate crashed', ['error' => $e->getMessage(), 'mr_iid' => $mrIid]);

            return 'the gate could not run';
        }
        if (!$gate->passed) {
            $together = count($pushing) > 1 ? ' This round\'s ' . count($pushing) . ' fixes are gated together, so the red step may come from another thread\'s fix; reply with `Triage:` to have me try this one alone.' : '';
            $this->failAll($project, $projectPath, $mrIid, $pushing, 'The gate is red with this change (' . ($gate->failedStep ?? 'a step') . '), so I did not push it.' . $together);

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
            $this->outcomes->setOutcome($projectPath, $mrIid, $id, $outcome);

            // Pushed, gate green: resolve only a thread Kelpie opened itself, never a person's.
            $notes = $this->writer->notes($project, $mrIid, $id);
            $this->replies->fixed($project, $mrIid, $id, $commit, $url, $fix['summary'], $notes !== null && $this->replies->openedByKelpie($notes, $id), $gate->ran === '' ? 'passed.' : $gate->ran);
        }

        return null;
    }

    /**
     * The thread's opening note, where a finding names the files it is about
     *
     * @param string $project      The project ID
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The thread
     *
     * @return string Empty when the thread cannot be read
     */
    private function openingNote(string $project, int $mrIid, string $discussionId): string
    {
        $notes = $this->writer->notes($project, $mrIid, $discussionId);

        return $notes === null || $notes === [] ? '' : $notes[0]->body;
    }

    /**
     * The first edited file a fix may not touch: not changed by the MR, not a test, not named in the thread
     *
     * The path is checked as written and again where it really lands: an edit to a symlink would rewrite the file it
     * points at, which may not be one the fix may touch, or be outside the checkout.
     *
     * @param string         $root    The checkout's real path
     * @param string[]       $changed The paths the MR changes
     * @param ProposedEdit[] $edits   One fix's edits
     * @param string         $finding The thread's opening note
     *
     * @return ?string Null when every edit lands in a file the fix may touch
     */
    private function outside(string $root, array $changed, array $edits, string $finding): ?string
    {
        foreach ($edits as $edit) {
            $file = (string) preg_replace('#^(\./)+#', '', $edit->file);
            if (!$this->mayTouch($file, $changed, $finding)) {
                return $edit->file;
            }

            // A file that does not exist yet is created by the edit step, which refuses one that cannot be made
            $real = realpath($root . '/' . $file);
            if ($real !== false && (!str_starts_with($real, $root . '/') || !$this->mayTouch(substr($real, strlen($root) + 1), $changed, $finding))) {
                return $edit->file;
            }
        }

        return null;
    }

    /**
     * Whether a fix may touch a repo-relative file: one the MR changes, a test, or one the thread names by path
     *
     * @param string   $file    The path
     * @param string[] $changed The paths the MR changes
     * @param string   $finding The thread's opening note
     *
     * @return bool
     */
    private function mayTouch(string $file, array $changed, string $finding): bool
    {
        if (in_array($file, $changed, true) || preg_match(self::TEST_FILE, $file) === 1) {
            return true;
        }

        return str_contains($file, '/') && preg_match('#(^|[^\w./-])' . preg_quote($file, '#') . '($|[^\w/-])#', $finding) === 1;
    }

    /**
     * Tell each thread the head moved, and set nothing
     *
     * @param string                                                                                        $project The project ID
     * @param int                                                                                           $mrIid   The MR IID
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>, model?: string}> $fixes   The fixes
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
     * @param string                                                                                        $project     The project ID
     * @param string                                                                                        $projectPath The project path
     * @param int                                                                                           $mrIid       The MR IID
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>, model?: string}> $fixes       The fixes
     * @param string                                                                                        $text        What to tell each thread
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
     * @param list<array{discussionId: string, summary: string, edits: list<ProposedEdit>, model?: string}> $fixes The fixes pushed
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

        // The model each proposal was made with is on its stored row. A row from before that was kept has none, and the
        // trailer then names Kelpie alone rather than a configured default that may not be what ran.
        $models = [];
        foreach ($fixes as $fix) {
            $models[$fix['model'] ?? ''] = true;
        }
        $trailers = '';
        foreach (array_keys($models) as $model) {
            $trailers .= Attribution::Autofix->trailer((string) $model) . "\n";
        }

        return 'fix(review): ' . $line . "\n\nApplies the changes Kelpie proposed in these review threads.\n\n" . $threads . "Kelpie-Autofix: propose\n" . $trailers;
    }
}
