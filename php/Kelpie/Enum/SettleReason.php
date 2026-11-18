<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * Why Kelpie resolved a thread that was settled
 *
 * @see \Kelpie\Tests\Enum\SettleReasonTest PHPUnit test case
 */
enum SettleReason: string
{
    case FixedPushed    = 'fixed_pushed';
    case Dismissed      = 'dismissed';
    case SummarySettled = 'summary_settled';
}
