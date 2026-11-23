<?php

declare(strict_types=1);

namespace Kelpie\Tests\Agent;

use Closure;
use Kelpie\Agent\FixerAgentInterface as FixerAgent;
use Kelpie\DataTransferObject\Completion;
use Kelpie\DataTransferObject\Thread;

/**
 * A fixer agent for tests: runs a closure that edits the checkout and records each run's gate output
 *
 * The closure returns the agent's closing words, or null for a run that did not complete
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
     * The context each run was given
     *
     * @var list<string>
     */
    private array $contexts = [];

    /**
     * Constructor
     *
     * @param Closure $edit Edits the checkout: (directory, gate output) to the closing words, null for a failed run
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
     * The context each run was given
     *
     * @return list<string>
     */
    public function contexts(): array
    {
        return $this->contexts;
    }

    /**
     * {@inheritDoc}
     */
    public function fix(string $directory, Thread $thread, string $model, string $context = '', ?string $gateFailure = null): ?Completion
    {
        $this->runs[]     = $gateFailure;
        $this->threads[]  = $thread->discussionId;
        $this->contexts[] = $context;

        $text = ($this->edit)($directory, $gateFailure);

        return is_string($text) ? new Completion($text, $model, 500, 100, 0.02) : null;
    }
}
