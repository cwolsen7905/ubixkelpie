<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * How one issue triage run ended
 *
 * @see \Kelpie\Tests\Enum\IssueTriageOutcomeTest PHPUnit test case
 */
enum IssueTriageOutcome: string
{
    case Triaged = 'triaged';
    case Skipped = 'skipped';
    case Failed  = 'failed';
}
