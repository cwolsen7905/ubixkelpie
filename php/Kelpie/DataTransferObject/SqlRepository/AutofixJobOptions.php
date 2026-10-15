<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\SqlRepository;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Criteria for finding autofix jobs
 *
 * @see \Kelpie\Tests\DataTransferObject\SqlRepository\AutofixJobOptionsTest PHPUnit test case
 */
final readonly class AutofixJobOptions implements Dto
{
    /**
     * Constructor
     *
     * @param ?int    $id          Job id
     * @param ?string $projectPath Project path with namespace
     * @param ?int    $mrIid       Merge request IID
     * @param ?string $threadKey   The discussion id, or '' for an MR-level job
     * @param ?string $status      Job status
     * @param bool    $openOnly    Only pending or running jobs
     * @param ?int    $limit       Maximum rows
     */
    public function __construct(
        public ?int $id = null,
        public ?string $projectPath = null,
        public ?int $mrIid = null,
        public ?string $threadKey = null,
        public ?string $status = null,
        public bool $openOnly = false,
        public ?int $limit = null,
    ) {
    }
}
