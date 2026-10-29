<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The answer to "may Kelpie spend more today": the cap that was reached, if any, with the figures
 *
 * @see \Kelpie\Tests\DataTransferObject\SpendCheckTest PHPUnit test case
 */
final readonly class SpendCheck implements Dto
{
    public const string NOT_ENROLLED = 'not_enrolled';

    /**
     * Constructor
     *
     * @param ?string $cap   Which cap was reached, `project`, `global` or `not_enrolled` (no row for the project, so nothing may run); null when under both
     * @param float   $spent Spend so far today against that cap, in USD
     * @param float   $limit That cap, in USD
     */
    public function __construct(
        public ?string $cap = null,
        public float $spent = 0.0,
        public float $limit = 0.0,
    ) {
    }
}
