<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\SqlRepository;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Criteria for finding thread outcome rows: the one row of a thread
 *
 * @see \Kelpie\Tests\DataTransferObject\SqlRepository\ThreadOutcomeOptionsTest PHPUnit test case
 */
final readonly class ThreadOutcomeOptions implements Dto
{
    /**
     * Constructor
     *
     * @param string $projectPath  The project path with namespace
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     */
    public function __construct(
        public string $projectPath,
        public int $mrIid,
        public string $discussionId,
    ) {
    }
}
