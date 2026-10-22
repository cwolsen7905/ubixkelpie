<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What the propose model answered for one thread: edits and a summary, or why there are none
 *
 * @see \Kelpie\Tests\DataTransferObject\ProposedEditsTest PHPUnit test case
 */
final readonly class ProposedEdits implements Dto
{
    /**
     * Constructor
     *
     * @param ProposedEdit[] $edits      The edits, empty when there are none to apply
     * @param string         $summary    One or two sentences on the change
     * @param string         $failure    Why there are no usable edits, empty when there are
     * @param ?Completion    $completion The model call, null when none was made
     * @param string         $editsJson  The edits as JSON, for the record
     */
    public function __construct(
        public array $edits,
        public string $summary,
        public string $failure = '',
        public ?Completion $completion = null,
        public string $editsJson = '',
    ) {
    }
}
