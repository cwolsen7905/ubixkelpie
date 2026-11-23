<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\ProjectMode;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * A validated partial update of one project's settings
 *
 * Only the columns present in `$changes` are to be written; absent is "leave alone", which a null value cannot say
 * (a null hard cap means unlimited and a null model means the default). The keys are `projects` column names and the
 * values are already the right type: `mode` a `ProjectMode`, counts `int`, spend caps a two-place decimal string, models
 * a string, hard caps and models also null.
 *
 * @see \Kelpie\Tests\DataTransferObject\ProjectSettingsPatchTest PHPUnit test case
 */
final readonly class ProjectSettingsPatch implements Dto
{
    /**
     * Constructor
     *
     * @param array<string, ProjectMode|int|string|null> $changes Column name => new value
     */
    public function __construct(
        public array $changes = [],
    ) {
    }
}
