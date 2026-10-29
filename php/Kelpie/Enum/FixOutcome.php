<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * What the fixer runner did about one thread
 *
 * @see \Kelpie\Tests\Enum\FixOutcomeTest PHPUnit test case
 */
enum FixOutcome: string
{
    case Disabled      = 'disabled';
    case Skipped       = 'skipped';
    case NotAFix       = 'not_a_fix';
    case HeadMoved     = 'head_moved';
    case Unsafe        = 'unsafe';
    case NoChange      = 'no_change';
    case EditsRejected = 'edits_rejected';
    case Proposed      = 'proposed';
    case OverCap       = 'over_cap';
    case Failed        = 'failed';
}
