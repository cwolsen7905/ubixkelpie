<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What the agent changed in a checkout, measured from git and not from the agent's own words
 *
 * @see \Kelpie\Tests\DataTransferObject\ChangeSetTest PHPUnit test case
 */
final readonly class ChangeSet implements Dto
{
    /**
     * Constructor
     *
     * @param int      $changedLines          Added plus removed lines
     * @param string[] $paths                 Repo-relative paths touched
     * @param bool     $deletesOrRenamesFiles Whether any file is deleted or renamed
     * @param bool     $weakensTests          Whether a test is deleted or skipped
     */
    public function __construct(
        public int $changedLines,
        public array $paths,
        public bool $deletesOrRenamesFiles,
        public bool $weakensTests,
    ) {
    }
}
