<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * How one issue fix run ended
 *
 * The value is stored on the job row, so it stays within 16 characters.
 *
 * @see \Kelpie\Tests\Enum\IssueFixOutcomeTest PHPUnit test case
 */
enum IssueFixOutcome: string
{
    /**
     * A merge request is open (or already was)
     */
    case Opened = 'opened';

    /**
     * One attempt was made and it did not produce a merge request: the issue says why and waits for a person
     */
    case HandedOff = 'handed_off';

    /**
     * Nothing to do: the label is gone, the issue is confidential or opted out, or a merge request already exists
     */
    case Skipped = 'skipped';

    /**
     * A spend cap was reached before any model call
     */
    case CapReached = 'cap_reached';

    /**
     * Nothing was changed anywhere and a later attempt may work: the issue could not be read, the clone failed, the model gave nothing
     */
    case Retry = 'retry';
}
