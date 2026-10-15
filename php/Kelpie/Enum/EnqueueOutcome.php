<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * What the coalescer did with an incoming job
 *
 * @see \Kelpie\Tests\Enum\EnqueueOutcomeTest PHPUnit test case
 *
 * @see \Kelpie\Tests\Repository\AutofixJob\EnqueueOutcomeTest PHPUnit test case
 */
enum EnqueueOutcome: string
{
    case Enqueued    = 'enqueued';
    case Coalesced   = 'coalesced';
    case CapExceeded = 'cap_exceeded';
}
