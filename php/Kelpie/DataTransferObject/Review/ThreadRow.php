<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One finding thread already on the merge request, read back from its marker and text
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ThreadRowTest PHPUnit test case
 */
final readonly class ThreadRow implements Dto
{
    /**
     * Constructor
     *
     * @param string $id    The finding id from the marker
     * @param string $file  The file, `-` when the thread text does not name one
     * @param string $title The finding title, or the id when the text has none
     * @param int    $line  The line the thread points at, 0 when unknown
     * @param string $sha   The commit the thread was posted on, empty when unknown
     */
    public function __construct(
        public string $id,
        public string $file,
        public string $title,
        public int $line,
        public string $sha,
    ) {
    }
}
