<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One exact-text edit: the text `old` occurs once in `file` and `new` replaces it
 *
 * @see \Kelpie\Tests\DataTransferObject\ProposedEditTest PHPUnit test case
 */
final readonly class ProposedEdit implements Dto
{
    /**
     * Constructor
     *
     * @param string $file Repo-relative path
     * @param string $old  The text to replace; must occur exactly once in the file
     * @param string $new  The replacement
     */
    public function __construct(
        public string $file,
        public string $old,
        public string $new,
    ) {
    }
}
