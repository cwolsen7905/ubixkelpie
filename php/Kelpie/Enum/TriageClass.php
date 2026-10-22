<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * What triage decided about one review thread
 *
 * @see \Kelpie\Tests\Enum\TriageClassTest PHPUnit test case
 */
enum TriageClass: string
{
    case Fix          = 'fix';
    case NeedsHuman   = 'needs_human';
    case NotAnIssue   = 'not_an_issue';
    case AlreadyFixed = 'already_fixed';
    case Defer        = 'defer';
}
