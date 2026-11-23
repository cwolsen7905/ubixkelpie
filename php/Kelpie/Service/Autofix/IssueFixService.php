<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\DataTransferObject\Completion;
use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\IssueDetails;
use Kelpie\DataTransferObject\Project;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\IssueFixOutcome;
use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\ProjectMode;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\GitLab\IssueClient;
use Kelpie\GitLab\MergeRequestOpener;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Sandbox\Checkout;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Service\Triage\IssueTriageService;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

/**
 * Fixes one issue a Developer gated: a branch, the edits, the limits, the repo's gate, a merge request, one attempt
 *
 * The branch `kelpie/fix-<iid>-<slug>` is cut from the target branch's head in a scratch checkout. The propose model's edits
 * must apply exactly, stay inside `SafetyPolicy` (measured from git, not from the model's words) and pass the project's
 * gate before anything leaves the machine. Then the branch is pushed (never forced) and a merge request to the
 * target branch is opened with `Closes #N` and a reviewer; the issue gets the link. Kelpie never merges or approves.
 *
 * It is one attempt. When the model has no safe edit, an edit is refused, or the gate is red, nothing is pushed: the issue
 * gets a comment with the reason and `kelpie::needs-human`, and a person removes and re-adds the gate label to ask again.
 * What is left to try again (nothing changed anywhere, such as an unreadable issue) is `Retry` for the worker.
 *
 * @see \Kelpie\Tests\Service\Autofix\IssueFixServiceTest PHPUnit test case
 */
final class IssueFixService
{
    public const string FIX_PROPOSED_LABEL = 'kelpie::fix-proposed';

    public const string MR_LABEL = 'kelpie';

    private const int MAX_TITLE = 100;

    private const int MAX_TEXT = 1500;

    private const string MODE = 'propose';

    /**
     * What the model calls of the run in hand cost, set as soon as it is known so a crash after it keeps its cost
     */
    private ?Completion $paid = null;

    /**
     * Constructor
     *
     * @param Logger                   $logger       Logger
     * @param GitlabClientFactory      $gitlab       Builds the issue client and the merge request opener
     * @param IssueProposeEditsService $propose      Asks the propose model for edits
     * @param EditApplyService         $editApplier  Applies the edits to the checkout
     * @param SafetyPolicy             $safetyPolicy The hard limits, applied to the real diff
     * @param IssueGateService         $gate         The project's gate
     * @param Checkout                 $checkout     The sandbox checkout
     * @param SpendCapService          $spendCaps    The daily spend caps
     * @param ProjectReader            $projects     The project's own gate command and protected paths
     * @param ThreadOutcomeWriter      $outcomes     Records what the run cost, which is what the caps count
     * @param AutofixSettings          $settings     The settings: kill switch, git URL, bot name
     * @param string                   $reviewer     Username to ask for a review of the merge request, empty for none
     * @param string                   $gateLabel    The label a Developer adds to ask for a fix
     * @param string                   $target       The branch the fix starts from and the merge request goes to
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly GitlabClientFactory $gitlab,
        private readonly IssueProposeEditsService $propose,
        private readonly EditApplyService $editApplier,
        private readonly SafetyPolicy $safetyPolicy,
        private readonly IssueGateService $gate,
        private readonly Checkout $checkout,
        private readonly SpendCapService $spendCaps,
        private readonly ProjectReader $projects,
        private readonly ThreadOutcomeWriter $outcomes,
        private readonly AutofixSettings $settings,
        private readonly string $reviewer = '',
        private readonly string $gateLabel = 'kelpie::fix',
        private readonly string $target = 'dev',
    ) {
    }

    /**
     * Fix one issue
     *
     * @param string $project The project path with namespace
     * @param int    $iid     The issue number in the project
     *
     * @return IssueFixOutcome
     */
    public function fix(string $project, int $iid): IssueFixOutcome
    {
        if (!$this->settings->enabled) {
            return IssueFixOutcome::Skipped;
        }

        $config = $this->projects->getByPath($project);
        if ($config === null || $config->mode === ProjectMode::Off) {
            return IssueFixOutcome::Skipped;
        }

        $issues = $this->gitlab->issueClient();
        $issue  = $issues->issue($project, $iid);
        if ($issue === null) {
            return IssueFixOutcome::Retry;
        }

        $skip = $this->skipReason($issue);
        if ($skip !== null) {
            $this->logger->info('Issue fix: skipped', ['issue_iid' => $iid, 'project_path' => $project, 'reason' => $skip]);

            return IssueFixOutcome::Skipped;
        }

        $opener = $this->gitlab->opener();
        $branch = $this->branch($iid, $issue->title);
        $open   = $opener->openFrom($project, $branch);
        if ($open === null) {
            return IssueFixOutcome::Retry;
        }
        if ($open !== []) {
            $this->logger->info('Issue fix: a merge request is already open', ['issue_iid' => $iid, 'project_path' => $project]);

            return IssueFixOutcome::Skipped;
        }

        $spend = $this->spendCaps->check($project);
        if ($spend->cap !== null) {
            $this->logger->warning('Issue fix: spend cap reached', ['cap' => $spend->cap, 'issue_iid' => $iid, 'project_path' => $project]);

            return $spend->cap === 'not_enrolled' ? IssueFixOutcome::Skipped : $this->handOff($issues, $project, $iid, 'the daily spend cap (`' . $spend->cap . '`) is reached, so I made no attempt. Add the label again tomorrow.', IssueFixOutcome::CapReached);
        }

        // The checkout opens detached at the target's head; the new branch exists only as the name `push()` gives HEAD.
        $base = $opener->branchSha($project, $this->target);
        if ($base === null || !$this->checkout->open($this->settings->gitBaseUrl . '/' . $project . '.git', $this->target, $base)) {
            $this->checkout->close();

            return IssueFixOutcome::Retry;
        }

        $this->paid = null;
        try {
            return $this->attempt($issues, $opener, $config, $iid, $issue, $branch);
        } catch (Throwable $e) {
            $this->logger->error('Issue fix: the run threw', ['error' => $e->getMessage(), 'issue_iid' => $iid, 'project_path' => $project]);
            $this->record($project, $iid, ThreadOutcome::Failed, 'The run threw.');

            return IssueFixOutcome::Retry;
        } finally {
            $this->checkout->close();
        }
    }

    /**
     * The branch for an issue: stable for one title, a plain ref
     *
     * @param int    $iid   The issue number
     * @param string $title The issue title, untrusted
     *
     * @return string
     */
    public function branch(int $iid, string $title): string
    {
        $slug = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'), 0, 40), '-');

        return 'kelpie/fix-' . $iid . '-' . ($slug === '' ? 'issue' : $slug);
    }

    /**
     * What to do with the issue in the checkout, up to and including the merge request
     *
     * @param IssueClient        $issues The issue client
     * @param MergeRequestOpener $opener The merge request opener
     * @param Project            $config The project
     * @param int                $iid    The issue number
     * @param IssueDetails       $issue  The issue
     * @param string             $branch The new branch
     *
     * @return IssueFixOutcome
     */
    private function attempt(IssueClient $issues, MergeRequestOpener $opener, Project $config, int $iid, IssueDetails $issue, string $branch): IssueFixOutcome
    {
        $project   = $config->path;
        $directory = $this->checkout->directory();

        $edits      = $this->propose->propose($issue, $directory, $project);
        $this->paid = $edits->completion;
        if ($edits->failure !== '') {
            if ($edits->completion === null) {
                return IssueFixOutcome::Retry; // The model said nothing: nothing was spent, nothing changed
            }

            return $this->handOff($issues, $project, $iid, $edits->failure);
        }

        $rejected = $this->editApplier->apply($directory, $edits->edits);
        if ($rejected !== null) {
            return $this->handOff($issues, $project, $iid, 'the edits I got did not apply exactly: ' . $rejected);
        }

        $changes = $this->checkout->stageAndMeasure();
        if ($changes === null) {
            return $this->handOff($issues, $project, $iid, 'git could not measure the edits.');
        }
        if ($changes->paths === []) {
            return $this->handOff($issues, $project, $iid, 'the edits changed nothing.');
        }

        $checked = $this->safetyPolicy->apply(
            new TriageVerdict(TriageClass::Fix, ''),
            new FixProposal($changes->changedLines, $changes->paths, $changes->deletesOrRenamesFiles, $changes->weakensTests, 0, 0, $this->target, false),
        );
        if ($checked->class !== TriageClass::Fix) {
            return $this->handOff($issues, $project, $iid, $checked->reason);
        }

        $protected = $this->safetyPolicy->protectedPath($changes->paths, $config->protectedPaths);
        if ($protected !== null) {
            return $this->handOff($issues, $project, $iid, 'the fix touches a path this project protects (`' . $protected . '`).');
        }

        $diff = $this->checkout->stagedDiff();
        if ($diff === null || trim($diff) === '') {
            return $this->handOff($issues, $project, $iid, 'git wrote no diff.');
        }
        if (preg_match('/<!--\s*kelpie:/i', $diff) === 1) {
            return $this->handOff($issues, $project, $iid, 'the change contains text that looks like one of my own markers.');
        }

        $gate = $this->gate->run($directory, $config->gateCommand);
        if (!$gate->passed) {
            return $this->handOff($issues, $project, $iid, 'the gate failed at `' . ($gate->failedStep ?? 'a step') . "`. The tail of its output:\n\n```\n" . $this->fenced($gate->output) . "\n```");
        }

        $summary = $edits->summary === '' ? 'Fixes #' . $iid . '.' : $this->clean($edits->summary);
        $commit  = $this->checkout->commit('fix: address #' . $iid . "\n\n" . $summary . "\n\nKelpie-Autofix: issue\n");
        if ($commit === null) {
            return $this->handOff($issues, $project, $iid, 'git could not commit.');
        }
        if (!$this->checkout->push($branch)) {
            return $this->handOff($issues, $project, $iid, 'the push of `' . $branch . '` was refused.');
        }

        $url = $opener->create($project, $branch, $this->target, $this->mrTitle($iid, $issue->title), $this->mrDescription($iid, $summary, $changes->paths, $gate->local), [self::MR_LABEL], $this->reviewerIds($opener));
        if ($url === null) {
            $this->logger->error('Issue fix: the merge request could not be opened after the push', ['branch' => $branch, 'issue_iid' => $iid, 'project_path' => $project]);

            return $this->handOff($issues, $project, $iid, ($gate->local ? 'the gate passed and `' : '`') . $branch . '` is pushed, but the merge request could not be opened. A person can open it from that branch.', pushed: true, diff: $diff);
        }

        $this->record($project, $iid, ThreadOutcome::Pushed, $summary, $edits->editsJson, $diff);
        $issues->comment($project, $iid, sprintf("**Kelpie opened a merge request: %s**\n\n%s\n\n%s Nothing is merged: a person reviews and merges it.\n\n<!-- kelpie:fix issue=%d result=opened -->", $url, $summary, $gate->local ? 'The gate passed.' : 'The gate was not run here: the merge request pipeline gates it.', $iid));
        $issues->addLabel($project, $iid, self::FIX_PROPOSED_LABEL);

        $this->logger->info('Issue fix: merge request opened', ['issue_iid' => $iid, 'project_path' => $project]);

        return IssueFixOutcome::Opened;
    }

    /**
     * Stop after one attempt: say why on the issue, label it for a person, and record the cost
     *
     * @param IssueClient     $issues  The issue client
     * @param string          $project The project path
     * @param int             $iid     The issue number
     * @param string          $reason  Why there is no merge request, a sentence that completes "Kelpie did not open a merge request: "
     * @param IssueFixOutcome $outcome The outcome to report when the comment is posted
     * @param bool            $pushed  Whether the branch was pushed (so a repeat cannot start over)
     * @param string          $diff    The diff, for the record
     *
     * @return IssueFixOutcome Retry when the comment could not be posted and nothing was pushed: nobody would know
     */
    private function handOff(IssueClient $issues, string $project, int $iid, string $reason, IssueFixOutcome $outcome = IssueFixOutcome::HandedOff, bool $pushed = false, string $diff = ''): IssueFixOutcome
    {
        $reason = $this->clean($reason);
        $this->logger->info('Issue fix: handed off', ['issue_iid' => $iid, 'project_path' => $project, 'reason' => mb_substr($reason, 0, 200)]);

        $body = sprintf("**Kelpie did not open a merge request**\n\nKelpie made one attempt: %s\n\nNothing was pushed%s. A person can take it from here, or remove and add the `%s` label to ask for another attempt.\n\n<!-- kelpie:fix issue=%d result=handoff -->", $reason, $pushed ? ' except that branch' : '', $this->gateLabel, $iid);
        if (!$issues->comment($project, $iid, $body) && !$pushed) {
            $this->record($project, $iid, ThreadOutcome::Failed, 'The hand-off comment could not be posted.');

            return IssueFixOutcome::Retry;
        }

        $issues->addLabel($project, $iid, IssueTriageClass::NeedsHuman->label());
        $this->record($project, $iid, ThreadOutcome::Failed, $reason, diff: $diff);

        return $outcome;
    }

    /**
     * Why this issue gets no fix, or null when it does
     *
     * @param IssueDetails $issue The issue
     *
     * @return ?string
     */
    private function skipReason(IssueDetails $issue): ?string
    {
        return match (true) {
            !in_array($this->gateLabel, $issue->labels, true)                    => 'the gate label is gone',
            $issue->confidential                                                  => 'confidential issue',
            in_array(IssueTriageService::SKIP_LABEL, $issue->labels, true)        => 'opted out with ' . IssueTriageService::SKIP_LABEL,
            default                                                               => null,
        };
    }

    /**
     * @param MergeRequestOpener $opener The merge request opener
     *
     * @return int[] The reviewer's id, none when no reviewer is set or GitLab does not know the name
     */
    private function reviewerIds(MergeRequestOpener $opener): array
    {
        if ($this->reviewer === '') {
            return [];
        }

        $id = $opener->userId($this->reviewer);
        if ($id === null) {
            $this->logger->warning('Issue fix: the reviewer could not be found, the merge request has none');
        }

        return $id === null ? [] : [$id];
    }

    /**
     * @param int    $iid   The issue number
     * @param string $title The issue title
     *
     * @return string One line, capped
     */
    private function mrTitle(int $iid, string $title): string
    {
        return mb_substr('Fix #' . $iid . ': ' . trim((string) preg_replace('/\s+/', ' ', $title)), 0, self::MAX_TITLE);
    }

    /**
     * @param int      $iid     The issue number
     * @param string   $summary The change in a sentence or two
     * @param string[] $paths   The files changed
     * @param bool     $local   Whether the gate ran here (false: the project's pipeline is its gate)
     *
     * @return string
     */
    private function mrDescription(int $iid, string $summary, array $paths, bool $local = true): string
    {
        $gate = $local ? 'The project\'s gate passed on this branch before it was pushed.' : 'The gate was not run before the push: this project\'s merge request pipeline is its gate.';

        return sprintf("%s\n\nFiles: `%s`\n\n%s Opened by Kelpie from the issue; it does not merge or approve.\n\nCloses #%d\n\n<!-- kelpie:fix issue=%d -->", $summary, implode('`, `', $paths), $gate, $iid, $iid);
    }

    /**
     * Make a model's or a tool's text safe to post: capped, no comment markers, no pings
     *
     * @param string $text The raw text
     *
     * @return string
     */
    private function clean(string $text): string
    {
        $text = str_replace(['<!--', '-->', '@'], ['', '', '@' . "\u{200B}"], $text);

        return trim(mb_substr($text, 0, self::MAX_TEXT));
    }

    /**
     * Gate output inside a code fence: no fence of its own, no markers, no pings
     *
     * @param string $output The tail of the gate's output
     *
     * @return string
     */
    private function fenced(string $output): string
    {
        return str_replace('```', "'''", $this->clean(mb_substr($output, -self::MAX_TEXT)));
    }

    /**
     * Note what the run cost; the daily caps count it
     *
     * @param string        $project The project path
     * @param int           $iid     The issue number
     * @param ThreadOutcome $outcome What became of it
     * @param string        $reason  Why
     * @param string        $edits   The edits as JSON
     * @param string        $diff    The diff
     *
     * @return void
     */
    private function record(string $project, int $iid, ThreadOutcome $outcome, string $reason, string $edits = '', string $diff = ''): void
    {
        try {
            $this->outcomes->record(new ThreadOutcomeRecord(
                $project,
                0,
                'issue-' . $iid,
                '',
                TriageClass::Fix->value,
                self::MODE,
                $outcome,
                mb_substr($reason, 0, 500),
                '',
                $this->paid->model ?? '',
                '',
                $this->paid->promptTokens ?? 0,
                $this->paid->completionTokens ?? 0,
                $this->paid?->costUsd,
                null,
                $edits,
                $diff,
            ));
        } catch (Throwable $e) {
            $this->logger->error('Issue fix: the outcome could not be recorded', ['error' => $e->getMessage(), 'issue_iid' => $iid, 'project_path' => $project]);
        }
    }
}
