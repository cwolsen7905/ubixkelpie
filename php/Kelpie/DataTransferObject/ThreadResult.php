<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\TriageStatus;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What a triage run did about one thread, for the summary note and the MR's status label
 *
 * @see \Kelpie\Tests\DataTransferObject\ThreadResultTest PHPUnit test case
 */
final readonly class ThreadResult implements Dto
{
    /**
     * Constructor
     *
     * @param TriageStatus $status         Pushed, Proposed or NeedsYou
     * @param string       $text           One line on what was done
     * @param bool         $awaitsReaction Whether a proposed change on the thread waits for a 👍 to be pushed
     */
    public function __construct(
        public TriageStatus $status,
        public string $text,
        public bool $awaitsReaction = false,
    ) {
    }
}
