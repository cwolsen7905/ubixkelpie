<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What GitLab says about a project: its path and its visibility
 *
 * @see \Kelpie\Tests\DataTransferObject\ProjectInfoTest PHPUnit test case
 */
final readonly class ProjectInfo implements Dto
{
    /**
     * Constructor
     *
     * @param string $path       The project path with namespace
     * @param string $visibility GitLab's visibility: public, internal or private
     */
    public function __construct(
        public string $path,
        public string $visibility,
    ) {
    }
}
