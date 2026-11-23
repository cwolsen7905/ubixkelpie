<?php

declare(strict_types=1);

namespace Kelpie\Agent;

use Kelpie\DataTransferObject\AgentRun;
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
     * @param string  $model       The model the agent runs on
     * @param string  $context     What triage and the author said about the thread, empty when nothing
     * @param ?string $gateFailure The previous attempt's gate output, when this is a retry
     *
     * @return AgentRun Whether it finished, and its closing words, model and cost; a run that failed after spending
     *                  still carries its cost, so the spend caps count it
     */
    public function fix(string $directory, Thread $thread, string $model, string $context = '', ?string $gateFailure = null): AgentRun;
}
