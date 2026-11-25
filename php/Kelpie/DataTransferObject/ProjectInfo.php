<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What GitLab says about a project: its path, its visibility and the license it detected
 *
 * @see \Kelpie\Tests\DataTransferObject\ProjectInfoTest PHPUnit test case
 */
final readonly class ProjectInfo implements Dto
{
    /**
     * License keys (as GitLab detects them) that make a repository open source
     */
    public const array OPEN_SOURCE_LICENSES = [
        '0bsd', 'agpl-3.0', 'apache-2.0', 'artistic-2.0', 'bsd-2-clause', 'bsd-3-clause', 'bsd-3-clause-clear', 'bsl-1.0',
        'cc0-1.0', 'epl-1.0', 'epl-2.0', 'eupl-1.2', 'gpl-2.0', 'gpl-3.0', 'isc', 'lgpl-2.1', 'lgpl-3.0', 'mit', 'mpl-2.0',
        'unlicense', 'zlib',
    ];

    /**
     * Constructor
     *
     * @param string $path       The project path with namespace
     * @param string $visibility GitLab's visibility: public, internal or private
     * @param string $license    The license key GitLab detected in the repository (e.g. bsd-3-clause); empty when none
     */
    public function __construct(
        public string $path,
        public string $visibility,
        public string $license = '',
    ) {
    }
}
