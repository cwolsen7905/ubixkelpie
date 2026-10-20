<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject\Review;

use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\Severity;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One finding of a review
 *
 * @see \Kelpie\Tests\DataTransferObject\Review\FindingTest PHPUnit test case
 */
final readonly class Finding implements Dto
{
    /**
     * Constructor
     *
     * @param Severity   $severity    How bad it is
     * @param Confidence $confidence  How sure the model is
     * @param string     $file        Path relative to the repository root
     * @param int        $line        Line on the new side of the diff, 0 when it is about a file or package
     * @param string     $title       Short title
     * @param string     $why         What is wrong, how it fails, the smallest fix
     * @param string     $fingerprint The model's slug of the issue
     * @param string     $id          Stable id: 12 hex of sha1(file|fingerprint), never the line or the wording
     */
    public function __construct(
        public Severity $severity,
        public Confidence $confidence,
        public string $file,
        public int $line,
        public string $title,
        public string $why,
        public string $fingerprint,
        public string $id,
    ) {
    }
}
