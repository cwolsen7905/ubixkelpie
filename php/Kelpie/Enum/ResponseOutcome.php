<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * What Kelpie did about one triaged thread (docs/design/mr-thread-autofix.md, section 5)
 *
 * @see \Kelpie\Tests\Enum\ResponseOutcomeTest PHPUnit test case
 */
enum ResponseOutcome: string
{
    case Replied         = 'replied';
    case Silent          = 'silent';
    case AlreadyAnswered = 'already_answered';
    case ClaimedByOther  = 'claimed_by_other';
    case Failed          = 'failed';
}
