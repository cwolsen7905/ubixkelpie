<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

use Kelpie\DataTransferObject\Thread;

/**
 * Edits files in a checkout to address one review thread
 *
 * It only edits: it never commits or pushes, and holds no GitLab credential.
 */
interface FixerAgentInterface
{
    /**
     * Run the agent
     *
     * @param string  $directory   The checkout
     * @param Thread  $thread      The thread to address
     * @param ?string $gateFailure The previous attempt's gate output, when this is a retry
     *
     * @return bool Whether the agent ran to completion
     */
    public function fix(string $directory, Thread $thread, ?string $gateFailure): bool;
}
