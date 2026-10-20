<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The reviewable diff of a merge request and the files it touches
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\ReviewDiffTest PHPUnit test case
 */
final readonly class ReviewDiff implements Dto
{
    /**
     * Constructor
     *
     * @param string   $text  The unified diff, without lockfiles, vendored and generated files
     * @param string[] $files The changed files, in the same filtering
     */
    public function __construct(
        public string $text,
        public array $files,
    ) {
    }
}
