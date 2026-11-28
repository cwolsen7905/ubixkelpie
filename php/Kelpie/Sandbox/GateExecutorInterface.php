<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\GateRun;

/**
 * Runs the steps of a gate (each an argv, no shell) in a checkout, in order, stopping at the first that fails
 *
 * The steps run the repository's own code, so where they run decides what that code can reach: in this process's
 * container (`LocalGateExecutor`) or in the gate pod, which holds no secrets and has no network (`RemoteGateExecutor`).
 * See docs/security/gate-pod.md.
 */
interface GateExecutorInterface
{
    /**
     * The most of a step's output that comes back (its tail)
     */
    public const int MAX_OUTPUT = 6000;

    /**
     * Run the steps
     *
     * @param string               $directory      The checkout, with whatever the steps need already installed in it (vendor/)
     * @param array<int, string[]> $steps          Each step's program and arguments, in order
     * @param int                  $timeoutSeconds Wall-clock limit of each step
     *
     * @return GateRun
     */
    public function run(string $directory, array $steps, int $timeoutSeconds): GateRun;
}
