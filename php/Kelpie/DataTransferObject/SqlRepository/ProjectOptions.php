<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\SqlRepository;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Criteria for finding projects: one by path, or every one that is not off
 *
 * @see \Kelpie\Tests\DataTransferObject\SqlRepository\ProjectOptionsTest PHPUnit test case
 */
final readonly class ProjectOptions implements Dto
{
    /**
     * Constructor
     *
     * @param ?string $path The project path with namespace; null for every project that is not off
     */
    public function __construct(
        public ?string $path = null,
    ) {
    }
}
