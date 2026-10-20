<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\DataTransferObject\Review\ReviewTally;
use Kelpie\DataTransferObject\Review\ThreadMemory;
use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\Severity;
use Kelpie\GitLab\Review\ReviewGitlab;
use Psr\Log\LoggerInterface as Logger;

/**
 * Turns a review's findings into threads, skipping what a person dismissed or the merge request already holds
 *
 * Per finding: dismissed is skipped; already open is skipped; not tied to one line goes in the summary; a line an
 * open thread covers goes in the summary too (a blocker is still posted); anything else opens a thread, inline when
 * GitLab takes the position. An open thread the model did not re-raise is listed as possibly fixed only when the
 * lines it points at changed since it was posted; the review never resolves a thread.
 *
 * A shadow run opens no thread and calls nothing: it lists what it would have opened. A dry run prints it.
 *
 * @see \Kelpie\Tests\Service\Review\ThreadPostServiceTest PHPUnit test case
 */
final class ThreadPostService
{
    /**
     * Constructor
     *
     * @param Logger               $logger               Logger
     * @param ReviewGitlab         $reviewGitlab         The review's GitLab calls
     * @param FindingMemoryService $findingMemoryService Matches findings to threads
     * @param SummaryRenderService $summaryRenderService Writes the thread bodies and summary lines
     * @param RepoGitService       $repoGitService       Tells whether lines moved
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ReviewGitlab $reviewGitlab,
        private readonly FindingMemoryService $findingMemoryService,
        private readonly SummaryRenderService $summaryRenderService,
        private readonly RepoGitService $repoGitService,
    ) {
    }

    /**
     * Post the findings
     *
     * @param ReviewSettings $settings The settings
     * @param ReviewResult   $result   The validated review
     * @param ThreadMemory   $memory   The threads already on the merge request
     *
     * @return ReviewTally
     */
    public function post(ReviewSettings $settings, ReviewResult $result, ThreadMemory $memory): ReviewTally
    {
        $live     = !$settings->dryRun && !$settings->shadow;
        $position = $live ? $this->reviewGitlab->position($settings) : null;
        if ($live && $position === null) {
            $this->logger->warning('Could not read the merge request diff version; threads will not be inline');
        }

        $seenIds   = [];
        $seenKeys  = [];
        $posted    = 0;
        $dismissed = 0;
        $failed    = 0;
        $blocking  = 0;
        $fileLevel = [];
        $sameLine  = [];
        $listed    = [];
        $output    = [];

        foreach ($result->findings as $finding) {
            $seenIds[] = $finding->id;
            if ($this->findingMemoryService->isDismissed($memory, $finding)) {
                $dismissed++;
                continue;
            }
            if ($finding->severity->canBlock() && $finding->confidence === Confidence::High) {
                $blocking++;
            }
            if ($this->findingMemoryService->isOpen($memory, $finding)) {
                $seenKeys[] = $finding->file . '|' . mb_strtolower($finding->title);
                continue;
            }
            if ($finding->line <= 0) {
                $fileLevel[] = $this->summaryRenderService->lineFor($finding, true);
                continue;
            }
            if ($finding->severity !== Severity::Blocker && $this->findingMemoryService->coversLine($memory, $finding)) {
                $sameLine[] = $this->summaryRenderService->lineFor($finding, false);
                continue;
            }

            $body = $this->summaryRenderService->threadBody($finding, $settings->headSha);
            if ($settings->shadow) {
                $listed[] = $this->summaryRenderService->lineFor($finding, true);
                $posted++;
            } elseif ($settings->dryRun) {
                $output[] = "\n--- would open a thread ---\n" . $body;
                $posted++;
            } elseif ($this->reviewGitlab->openThread($settings, $body, $finding->file, $finding->line, $position)) {
                $posted++;
            } else {
                $failed++;
            }
        }

        $stale = [];
        foreach ($memory->open as $row) {
            if (in_array($row->id, $seenIds, true) || in_array($row->file . '|' . mb_strtolower($row->title), $seenKeys, true)) {
                continue;
            }
            if ($this->repoGitService->changedNear($settings, $row->file, $row->line, $row->sha)) {
                $stale[] = $this->summaryRenderService->neutralize(sprintf('- `%s`: %s', $row->file, $row->title));
            }
        }

        return new ReviewTally($posted, $dismissed, count($memory->open) - count($stale), $failed, $blocking, $fileLevel, $sameLine, $stale, $listed, $output);
    }
}
