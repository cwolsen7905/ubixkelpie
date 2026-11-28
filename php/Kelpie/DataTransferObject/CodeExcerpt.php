<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * Lines of one file at a commit, numbered, as triage is shown them; no text when there are none
 *
 * @see \Kelpie\Tests\DataTransferObject\CodeExcerptTest PHPUnit test case
 */
final readonly class CodeExcerpt implements Dto
{
    /**
     * Constructor
     *
     * @param string $path The file's path
     * @param int    $from The first line shown
     * @param int    $to   The last line shown
     * @param string $sha  The commit the lines are from
     * @param string $text The lines, each prefixed with its number
     */
    public function __construct(
        public string $path = '',
        public int $from = 0,
        public int $to = 0,
        public string $sha = '',
        public string $text = '',
    ) {
    }
}
