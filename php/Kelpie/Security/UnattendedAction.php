<?php

declare(strict_types=1);

namespace Kelpie\Security;

/**
 * The only things an unattended Kelpie run (worker or sweep) may do
 *
 * Anything that is not a case here is refused: nobody is there to approve it. Approving, merging, deleting,
 * force-pushing, resolving a thread Kelpie did not open and running other programs are all absent on purpose.
 *
 * @see \Kelpie\Tests\Security\UnattendedActionTest PHPUnit test case
 */
enum UnattendedAction: string
{
    case ReadRepo      = 'read_repo';
    case EditFiles     = 'edit_files';
    case RunGate       = 'run_gate';
    case GitRead       = 'git_read';
    case GitCheckout   = 'git_checkout';
    case GitCommit     = 'git_commit';
    case GitPush       = 'git_push';
    case CleanCheckout = 'clean_checkout';
    case PostThread    = 'post_thread';
    case SetLabel      = 'set_label';
}
