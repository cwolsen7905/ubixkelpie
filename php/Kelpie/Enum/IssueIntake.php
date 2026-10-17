<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * What intake made of one Issue Hook event
 *
 * @see \Kelpie\Tests\Enum\IssueIntakeTest PHPUnit test case
 */
enum IssueIntake: string
{
    case Triage  = 'triage';
    case Gate    = 'gate';
    case Ignored = 'ignored';
}
