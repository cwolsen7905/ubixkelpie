<?php

declare(strict_types=1);

namespace Kelpie\Tests\Agent;

use Closure;
use Kelpie\Agent\FixerAgentInterface as FixerAgent;
use Kelpie\DataTransferObject\Thread;

/**
 * A fixer agent for tests: runs a closure that edits the checkout and records each run's gate output
 */
final class ScriptedFixerAgent implements FixerAgent
{
    /**
     * The gate output given to each run (null on the first)
     *
     * @var list<?string>
     */
    private array $runs = [];

    /**
     * The thread IDs the agent was asked about
     *
     * @var list<string>
     */
    private array $threads = [];

    /**
     * Constructor
     *
     * @param Closure $edit Edits the checkout: (directory, gate output) to success
     */
    public function __construct(
        private readonly Closure $edit,
    ) {
    }

    /**
     * The gate output given to each run
     *
     * @return list<?string>
     */
    public function runs(): array
    {
        return $this->runs;
    }

    /**
     * The thread IDs the agent was asked about
     *
     * @return list<string>
     */
    public function threads(): array
    {
        return $this->threads;
    }

    /**
     * {@inheritDoc}
     */
    public function fix(string $directory, Thread $thread, ?string $gateFailure): bool
    {
        $this->runs[]    = $gateFailure;
        $this->threads[] = $thread->discussionId;

        return (bool) ($this->edit)($directory, $gateFailure);
    }
}
