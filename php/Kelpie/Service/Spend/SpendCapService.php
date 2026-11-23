<?php

declare(strict_types=1);

namespace Kelpie\Service\Spend;

use Kelpie\DataTransferObject\SpendCheck;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Psr\Log\LoggerInterface as Logger;

/**
 * Says whether the day's model spend has reached a cap (by the database's clock), per project or for all of Kelpie (docs/design/spend-caps.md)
 *
 * Each cap has a soft limit (the current value) and a hard limit (null is unlimited). A hard cap reached stops the run; a
 * soft cap passed only logs a warning and the run continues.
 *
 * @see \Kelpie\Tests\Service\Spend\SpendCapServiceTest PHPUnit test case
 */
final class SpendCapService
{
    private const float DEFAULT_GLOBAL_CAP = 10.0;

    /**
     * Constructor
     *
     * @param Logger              $logger   Logger
     * @param ProjectReader       $projects The projects, for the per-project cap
     * @param ThreadOutcomeReader $outcomes The record of what each run cost
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProjectReader $projects,
        private readonly ThreadOutcomeReader $outcomes,
    ) {
    }

    /**
     * Whether a model call for the project may start now
     *
     * A reached hard cap (project or global) stops the run and is returned. A passed soft cap only logs a warning and the
     * run continues, so the returned check has no cap.
     *
     * @param string $projectPath The project path with namespace
     *
     * @return SpendCheck The hard cap reached, or none
     */
    public function check(string $projectPath): SpendCheck
    {
        $project = $this->projects->getByPath($projectPath);
        if ($project === null) {
            return new SpendCheck(SpendCheck::NOT_ENROLLED); // No row means off: nothing to spend
        }

        $spent = $this->outcomes->costToday($projectPath);

        // Hard caps stop work; a null hard limit is unlimited and never reached
        $projectHard = $project->maxCostUsdDayHard;
        if ($projectHard !== null && $spent >= (float) $projectHard) {
            return new SpendCheck('project', $spent, (float) $projectHard);
        }

        $globalSpent = $this->outcomes->costToday(null);
        $globalHard  = $this->globalHardCap();
        if ($globalHard !== null && $globalSpent >= $globalHard) {
            return new SpendCheck('global', $globalSpent, $globalHard);
        }

        // Soft caps warn and continue
        $projectSoft = (float) $project->maxCostUsdDay;
        if ($spent >= $projectSoft) {
            $this->logger->warning('Soft spend cap passed: project daily spend', ['project_path' => $projectPath, 'spent' => $spent, 'soft_cap' => $projectSoft]);
        }

        $globalSoft = $this->globalCap();
        if ($globalSpent >= $globalSoft) {
            $this->logger->warning('Soft spend cap passed: global daily spend', ['spent' => $globalSpent, 'soft_cap' => $globalSoft]);
        }

        return new SpendCheck();
    }

    /**
     * The global daily cap from the environment, the default when it is unset or not a non-negative number
     *
     * @return float
     */
    private function globalCap(): float
    {
        $raw = trim((string) getenv('KELPIE_MAX_COST_USD_DAY_GLOBAL'));
        if ($raw === '' || !is_numeric($raw) || (float) $raw < 0.0) {
            if ($raw !== '') {
                $this->logger->warning('KELPIE_MAX_COST_USD_DAY_GLOBAL is not a non-negative number, using the default', ['value' => $raw]);
            }

            return self::DEFAULT_GLOBAL_CAP;
        }

        return (float) $raw;
    }

    /**
     * The global daily hard cap from the environment; null (unlimited) when it is unset, and also when it is set but not a
     * non-negative number (a typo must never stop the work)
     *
     * @return ?float
     */
    private function globalHardCap(): ?float
    {
        $raw = trim((string) getenv('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD'));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float) $raw < 0.0) {
            $this->logger->warning('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD is not a non-negative number, treating it as unlimited', ['value' => $raw]);

            return null;
        }

        return (float) $raw;
    }
}
