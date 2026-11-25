<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\DataTransferObject\Review\ThreadMemory;
use Kelpie\Enum\Review\FindingsLevel;
use Kelpie\Enum\Review\ReviewStatus;
use Kelpie\Enum\Review\Severity;
use Kelpie\GitLab\Review\ReviewGitlab;
use Psr\Log\LoggerInterface as Logger;

/**
 * Keeps the review's two scoped labels on the merge request: `kelpie-review::…` (where the review is) and
 * `kelpie-findings::…` (the worst finding still open)
 *
 * Each set stays exclusive: setting one takes the rest of its set off. The labels are created in the project the
 * first time they are needed. A label that cannot be set is logged and never fails the review. A shadow run sets
 * none; a dry run prints what it would set.
 *
 * @see \Kelpie\Tests\Service\Review\ReviewLabelServiceTest PHPUnit test case
 */
final class ReviewLabelService
{
    /**
     * Whether this run has already made sure the labels exist
     */
    private bool $ensured = false;

    /**
     * Constructor
     *
     * @param Logger               $logger               Logger
     * @param ReviewGitlab         $reviewGitlab         The review's GitLab calls
     * @param FindingMemoryService $findingMemoryService What the merge request already holds
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ReviewGitlab $reviewGitlab,
        private readonly FindingMemoryService $findingMemoryService,
    ) {
    }

    /**
     * Set the status label and, when given, the findings label
     *
     * @param ReviewSettings $settings The settings
     * @param ReviewStatus   $status   Where the review is
     * @param ?FindingsLevel $level    The worst finding open, null to leave the findings label as it is
     *
     * @return list<string> What a dry run would set; empty on a live run
     */
    public function mark(ReviewSettings $settings, ReviewStatus $status, ?FindingsLevel $level): array
    {
        if ($settings->shadow || (!$settings->dryRun && ($settings->token === '' || $settings->mrIid <= 0))) {
            return [];
        }

        $add    = [$status->value];
        $remove = ReviewStatus::othersThan($status);
        if ($level !== null) {
            $add[]  = $level->value;
            $remove = [...$remove, ...FindingsLevel::othersThan($level)];
        }

        if ($settings->dryRun) {
            return [sprintf("\n--- would set the labels: %s ---", implode(', ', $add))];
        }

        $this->ensure($settings);
        if (!$this->reviewGitlab->changeLabels($settings, $add, $remove)) {
            $this->logger->warning('Review labels could not be set', ['labels' => $add, 'mr_iid' => $settings->mrIid]);
        }

        return [];
    }

    /**
     * The worst finding open after a run: the threads already open, plus this run's findings a person has not dismissed
     *
     * An open thread whose text does not say its severity counts as a minor, so it is never read as none open.
     *
     * @param ThreadMemory  $memory What the merge request held before the run's threads were posted
     * @param ?ReviewResult $result This run's review, null when no model ran
     *
     * @return FindingsLevel
     */
    public function level(ThreadMemory $memory, ?ReviewResult $result = null): FindingsLevel
    {
        $severities = [];
        foreach ($memory->open as $row) {
            $severities[] = $row->severity ?? Severity::Minor;
        }
        foreach ($result === null ? [] : $result->findings as $finding) {
            if (!$this->findingMemoryService->isDismissed($memory, $finding)) {
                $severities[] = $finding->severity;
            }
        }

        $worst = null;
        foreach ([Severity::Blocker, Severity::Major, Severity::Minor, Severity::Nit] as $severity) {
            if (in_array($severity, $severities, true)) {
                $worst = $severity;
                break;
            }
        }

        return FindingsLevel::fromWorst($worst);
    }

    /**
     * Create the labels the project does not have yet, once per run
     *
     * @param ReviewSettings $settings The settings
     *
     * @return void
     */
    private function ensure(ReviewSettings $settings): void
    {
        if ($this->ensured) {
            return;
        }
        $this->ensured = true;

        $have = $this->reviewGitlab->labelNames($settings);
        if ($have === null) {
            $this->logger->warning('Review labels could not be listed; setting them may fail', ['project' => $settings->project]);

            return;
        }

        $wanted = [];
        foreach (ReviewStatus::cases() as $status) {
            $wanted[$status->value] = [$status->colour(), $status->description()];
        }
        foreach (FindingsLevel::cases() as $level) {
            $wanted[$level->value] = [$level->colour(), $level->description()];
        }

        foreach ($wanted as $name => [$colour, $description]) {
            if (!in_array($name, $have, true) && !$this->reviewGitlab->createLabel($settings, $name, $colour, $description)) {
                $this->logger->warning('Review label could not be created', ['label' => $name, 'project' => $settings->project]);
            }
        }
    }
}
