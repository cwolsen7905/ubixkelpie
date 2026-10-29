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
     * @param string $projectPath The project path with namespace
     *
     * @return SpendCheck The cap reached, or none
     */
    public function check(string $projectPath): SpendCheck
    {
        $project = $this->projects->getByPath($projectPath);
        if ($project === null) {
            return new SpendCheck(SpendCheck::NOT_ENROLLED); // No row means off: nothing to spend
        }

        $limit = (float) $project->maxCostUsdDay;
        $spent = $this->outcomes->costToday($projectPath);
        if ($spent >= $limit) {
            return new SpendCheck('project', $spent, $limit);
        }

        $globalLimit = $this->globalCap();
        $globalSpent = $this->outcomes->costToday(null);
        if ($globalSpent >= $globalLimit) {
            return new SpendCheck('global', $globalSpent, $globalLimit);
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
}
