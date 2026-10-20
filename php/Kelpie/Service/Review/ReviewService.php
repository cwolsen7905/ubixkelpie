<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewDiff;
use Kelpie\DataTransferObject\Review\ReviewOutcome;
use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\DataTransferObject\Review\ReviewUsage;
use Kelpie\DataTransferObject\Review\ThreadMemory;
use Kelpie\GitLab\Review\ReviewGitlab;
use Kelpie\Prompt\Review\ReviewPrompt;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Reviews one merge request: gates before any model call, the model run, then threads and one summary note
 *
 * Behaves as the shell review (`ci/ai-review/review.sh`) does, with the same markers, so threads from either
 * engine dedupe against each other. Exit: 0 reviewed or deliberately skipped, 1 a standing high-confidence blocker
 * or major in blocking mode, 75 could not run (the job is allowed to fail on 75, so being unconfigured is never a
 * red pipeline). A shadow run never exits 1 and posts only its own labelled summary note.
 *
 * @see \Kelpie\Tests\Service\Review\ReviewServiceTest PHPUnit test case
 */
final class ReviewService
{
    /**
     * Constructor
     *
     * @param Logger               $logger               Logger
     * @param JsonService          $jsonService          JSON service
     * @param ReviewGitlab         $reviewGitlab         The review's GitLab calls
     * @param RepoGitService       $repoGitService       The diff and files at a commit
     * @param SkipPolicyService    $skipPolicyService    The gates before the model
     * @param StandardsService     $standardsService     The repository's standards
     * @param FindingMemoryService $findingMemoryService What the merge request already holds
     * @param ReviewPrompt         $reviewPrompt         The prompt
     * @param ReviewAgentService   $reviewAgentService   The model run
     * @param ReviewParserService  $reviewParserService  Validates the reply
     * @param ThreadPostService    $threadPostService    Posts the findings
     * @param SummaryRenderService $summaryRenderService Writes the notes
     * @param ReviewUsageService   $reviewUsageService   Sums the usage
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly JsonService $jsonService,
        private readonly ReviewGitlab $reviewGitlab,
        private readonly RepoGitService $repoGitService,
        private readonly SkipPolicyService $skipPolicyService,
        private readonly StandardsService $standardsService,
        private readonly FindingMemoryService $findingMemoryService,
        private readonly ReviewPrompt $reviewPrompt,
        private readonly ReviewAgentService $reviewAgentService,
        private readonly ReviewParserService $reviewParserService,
        private readonly ThreadPostService $threadPostService,
        private readonly SummaryRenderService $summaryRenderService,
        private readonly ReviewUsageService $reviewUsageService,
    ) {
    }

    /**
     * Review the merge request
     *
     * @param ReviewSettings $settings The settings
     *
     * @return ReviewOutcome
     */
    public function review(ReviewSettings $settings): ReviewOutcome
    {
        if ($this->skipPolicyService->isDraft($settings->title)) {
            $this->logger->info('Draft merge request, review skipped');

            return new ReviewOutcome(0);
        }

        if (!$settings->dryRun) {
            $missing = match (true) {
                $settings->token === ''                                                        => 'AI_REVIEW_GITLAB_TOKEN is not set.',
                (string) getenv('OPENROUTER_API_KEY') === '' && $settings->cannedFile === ''   => 'OPENROUTER_API_KEY is not set.',
                $settings->mrIid <= 0                                                          => 'not a merge request pipeline.',
                default                                                                        => null,
            };
            if ($missing !== null) {
                return $this->unreviewed($settings, new ThreadMemory(), $missing, 0);
            }
        }

        $diff = $this->repoGitService->diff($settings);
        if ($diff === null) {
            $why = $settings->baseSha === '' ? 'CI_MERGE_REQUEST_DIFF_BASE_SHA is not set.' : sprintf('could not diff against %s (is the base commit fetched?).', $settings->baseSha);

            return $this->unreviewed($settings, new ThreadMemory(), $why, 0);
        }

        $reason = $this->skipPolicyService->skipReason($diff);

        $memory = new ThreadMemory();
        $botId  = 0;
        if (!$settings->dryRun || $settings->existing !== '') {
            $discussions = $this->discussions($settings);
            if ($discussions === null) {
                return $this->unreviewed($settings, $memory, "could not read the MR's discussions.", 0);
            }
            $botId  = $settings->dryRun ? 0 : $this->reviewGitlab->botId($settings);
            $memory = $this->findingMemoryService->read($settings, $discussions, $botId);
        }

        $round = max($memory->summaryRound, 1);
        if ($memory->summaryNoteId !== null && $memory->summarySha !== $settings->headSha) {
            $round = $memory->summaryRound + 1;
        }

        if ($reason !== null) {
            $this->logger->info('Review skipped', ['reason' => $reason]);
            $posted = $this->putSummary($settings, $memory, sprintf("**AI review: skipped.** No model call was made: %s.\n\n_On `%s`._", $reason, substr($settings->headSha, 0, 8)), $round, new ReviewUsage(), false);

            return new ReviewOutcome(0, $posted->lines);
        }

        if ($this->skipPolicyService->roundsExhausted($settings, $round)) {
            $note   = sprintf('**AI review: round limit reached.** This merge request has had %d automated review rounds. Add the `%s` label to allow more; a person should review it either way.', $settings->maxRounds, $settings->extraLabel);
            $posted = $this->putSummary($settings, $memory, $note, $round - 1, new ReviewUsage(), false);

            return new ReviewOutcome(0, $posted->lines);
        }

        $tooLarge = $this->skipPolicyService->tooLarge($settings, $diff);
        if ($tooLarge !== null) {
            $posted = $this->putSummary($settings, $memory, '**AI review: too large, human review required.** ' . $tooLarge, $round, new ReviewUsage(), false);

            return new ReviewOutcome(0, $posted->lines);
        }

        [$result, $usage] = $this->modelResult($settings, $memory, $diff);
        if ($result === null) {
            return $this->unreviewed($settings, $memory, 'the model did not return a valid review after a retry.', $round, $usage);
        }

        $lines = ['=== review JSON ===', $this->reviewJson($result)];

        $memory = $this->freshMemory($settings, $memory, $botId);
        $tally  = $this->threadPostService->post($settings, $result, $memory);
        array_push($lines, ...$tally->output);

        $body   = $result->findings === [] && $tally->stale === [] ? $this->summaryRenderService->clean($settings, $result, $diff, $round) : $this->summaryRenderService->noteWithFindings($settings, $result, $tally, $round);
        $posted = $this->putSummary($settings, $memory, $body, $round, $usage, true);
        array_push($lines, ...$posted->lines);
        $failed = $tally->failed + ($posted->exitCode === 0 ? 0 : 1);

        if ($failed > 0) {
            $this->logger->error('Review comments could not be posted', ['failed' => $failed]);

            return new ReviewOutcome(ReviewOutcome::UNREVIEWED, $lines);
        }
        if ($tally->blocking > 0) {
            $this->logger->warning('Blocking review findings', ['count' => $tally->blocking, 'mode' => ($settings->blocking ? 'blocking' : 'advisory')]);

            return new ReviewOutcome($settings->blocking && !$settings->shadow ? 1 : 0, $lines);
        }

        return new ReviewOutcome(0, $lines);
    }

    /**
     * @param ReviewSettings $settings The settings
     *
     * @return ?list<mixed> The discussions, null when they could not be read
     */
    private function discussions(ReviewSettings $settings): ?array
    {
        if ($settings->existing !== '') {
            try {
                return array_values($this->jsonService->decode(is_file($settings->existing) ? (string) file_get_contents($settings->existing) : '[]'));
            } catch (DtoException $e) {
                $this->logger->error('The existing-discussions file is not JSON', ['error' => $e->getMessage()]);

                return [];
            }
        }

        return $settings->dryRun ? [] : $this->reviewGitlab->discussions($settings);
    }

    /**
     * The model's validated review, with one retry that says what was wrong
     *
     * @param ReviewSettings $settings The settings
     * @param ThreadMemory   $memory   What the merge request holds
     * @param ReviewDiff     $diff     The diff
     *
     * @return array{0: ?ReviewResult, 1: ReviewUsage}
     */
    private function modelResult(ReviewSettings $settings, ThreadMemory $memory, ReviewDiff $diff): array
    {
        $standards = $this->standardsService->read($settings);
        $raised    = $this->findingMemoryService->raisedList($memory);
        $usage     = new ReviewUsage();
        $retry     = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($settings->cannedFile !== '') {
                $reply = (is_file($settings->cannedFile) ? (string) file_get_contents($settings->cannedFile) : '');
            } else {
                [$reply, $used] = $this->reviewAgentService->run($settings, $this->reviewPrompt->system(), $this->reviewPrompt->context($settings->title, $standards, $raised, $diff->text, $retry));
                $usage          = $this->reviewUsageService->plus($usage, $used);
                if ($reply === null) {
                    $this->logger->warning('Review model run failed', ['attempt' => $attempt]);
                    $retry = 'Your previous run failed before producing a reply. Try again.';
                    continue;
                }
            }

            $parsed = $this->reviewParserService->parse($reply);
            if ($parsed instanceof ReviewResult) {
                return [$parsed, $usage];
            }
            $this->logger->warning('Review reply was not a valid review', ['attempt' => $attempt, 'problem' => $parsed]);
            $retry = $parsed;
        }

        return [null, $usage];
    }

    /**
     * The model run takes minutes and another job may have posted since the first read: read again, so a thread that
     * appeared meanwhile is deduped rather than posted twice, and take a summary note that appeared
     *
     * @param ReviewSettings $settings The settings
     * @param ThreadMemory   $memory   The first read
     * @param int            $botId    The review token's user ID
     *
     * @return ThreadMemory
     */
    private function freshMemory(ReviewSettings $settings, ThreadMemory $memory, int $botId): ThreadMemory
    {
        if ($settings->dryRun || $settings->existing !== '') {
            return $memory;
        }

        $discussions = $this->reviewGitlab->discussions($settings);
        if ($discussions === null) {
            $this->logger->warning('Could not reread the discussions; deduping against the earlier read');

            return $memory;
        }

        $fresh = $this->findingMemoryService->read($settings, $discussions, $botId);

        return $fresh->summaryNoteId === null && $memory->summaryNoteId !== null ? new ThreadMemory($fresh->open, $fresh->dismissed, $memory->summaryNoteId, $memory->summaryRound, $memory->summarySha) : $fresh;
    }

    /**
     * Post or replace the one summary note
     *
     * @param ReviewSettings $settings    The settings
     * @param ThreadMemory   $memory      What the merge request holds (the note to replace)
     * @param string         $body        The body
     * @param int            $round       The round
     * @param ReviewUsage    $usage       The summed usage
     * @param bool           $modelCalled Whether a model call ran
     *
     * @return ReviewOutcome Exit 0 when it was posted or printed, 75 when GitLab refused it; the lines are what a dry run would post
     */
    private function putSummary(ReviewSettings $settings, ThreadMemory $memory, string $body, int $round, ReviewUsage $usage, bool $modelCalled): ReviewOutcome
    {
        $note = $this->summaryRenderService->note($settings, $body, $round, $this->summaryRenderService->stamp($settings, $usage, $modelCalled));

        if ($settings->dryRun) {
            return new ReviewOutcome(0, [sprintf("\n--- would %s the summary note ---\n%s", $memory->summaryNoteId !== null ? 'replace' : 'post', $note)]);
        }

        $ok = $memory->summaryNoteId !== null ? $this->reviewGitlab->editNote($settings, $memory->summaryNoteId, $note) : $this->reviewGitlab->postNote($settings, $note);

        return new ReviewOutcome($ok ? 0 : ReviewOutcome::UNREVIEWED);
    }

    /**
     * A review that could not run: say so in the summary when there is a token, and exit 75
     *
     * @param ReviewSettings $settings The settings
     * @param ThreadMemory   $memory   What the merge request holds
     * @param string         $why      Why it could not run
     * @param int            $round    The round
     * @param ?ReviewUsage   $usage    What the model calls so far cost, null when none ran
     *
     * @return ReviewOutcome
     */
    private function unreviewed(ReviewSettings $settings, ThreadMemory $memory, string $why, int $round, ?ReviewUsage $usage = null): ReviewOutcome
    {
        $this->logger->error('Review UNREVIEWED', ['why' => $why]);

        $lines = [];
        if ($settings->token !== '' || $settings->dryRun) {
            $lines = $this->putSummary($settings, $memory, sprintf("**AI review: UNREVIEWED.** %s\n\nNo automated review ran on this commit; a person must review it.", $why), $round, $usage ?? new ReviewUsage(), $usage !== null)->lines;
        }

        return new ReviewOutcome(ReviewOutcome::UNREVIEWED, $lines);
    }

    /**
     * @param ReviewResult $result The review
     *
     * @return string
     */
    private function reviewJson(ReviewResult $result): string
    {
        $findings = [];
        foreach ($result->findings as $finding) {
            $findings[] = [
                'confidence'  => $finding->confidence->value,
                'file'        => $finding->file,
                'fingerprint' => $finding->fingerprint,
                'id'          => $finding->id,
                'line'        => $finding->line,
                'severity'    => $finding->severity->value,
                'title'       => $finding->title,
                'why'         => $finding->why,
            ];
        }

        return $this->jsonService->encode(['verdict' => $result->verdict, 'summary' => $result->summary, 'findings' => $findings]);
    }
}
