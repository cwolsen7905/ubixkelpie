<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\ProjectMode;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One project's row in `projects`: its mode and its settings
 *
 * Null settings mean "use Kelpie's default"; the service that needs the setting knows the default.
 *
 * @see \Kelpie\Tests\DataTransferObject\ProjectTest PHPUnit test case
 */
final readonly class Project implements Dto
{
    /**
     * Constructor
     *
     * @param string      $path                  The project path with namespace
     * @param ProjectMode $mode                  How far Kelpie may go
     * @param ?string     $gateCommand           The project's own gate; null for the default gate
     * @param string[]    $protectedPaths        Extra path globs the fixer must not touch
     * @param ?string     $triageModel           The triage model; null for the default model
     * @param ?string     $reviewModel           The review model; null for the default model
     * @param int         $maxIssuesPerDay       Issues triaged per day in this project
     * @param int         $maxIssuesPerAuthorDay Issues triaged per day for one author
     * @param string      $maxCostUsdDay         The daily spend cap in USD, as the decimal the database holds
     * @param int         $maxFixCommitsPerMr    Fix commits per merge request
     */
    public function __construct(
        public string $path,
        public ProjectMode $mode = ProjectMode::Off,
        public ?string $gateCommand = null,
        public array $protectedPaths = [],
        public ?string $triageModel = null,
        public ?string $reviewModel = null,
        public int $maxIssuesPerDay = 30,
        public int $maxIssuesPerAuthorDay = 10,
        public string $maxCostUsdDay = '2.00',
        public int $maxFixCommitsPerMr = 3,
    ) {
    }
}
