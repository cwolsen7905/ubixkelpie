<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * What became of the thing Kelpie did about a thread
 *
 * `Proposed` and `Answered` are written by the run. `Pushed` is a fix the push round committed and pushed on its own;
 * `Applied` is one pushed after a person's thumbs-up. The rest are for what happens to a proposal afterwards.
 *
 * @see \Kelpie\Tests\Enum\ThreadOutcomeTest PHPUnit test case
 */
enum ThreadOutcome: string
{
    case Proposed   = 'proposed';
    case Answered   = 'answered';
    case Applied    = 'applied';
    case Pushed     = 'pushed';
    case Declined   = 'declined';
    case Overridden = 'overridden';
    case Reverted   = 'reverted';
    case Reopened   = 'reopened';
    case Failed     = 'failed';
}
