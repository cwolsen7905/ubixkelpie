<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

/**
 * Clears the gate pod's container between runs: stops every process but the server, and confirms none is left
 */
interface ProcessReaperInterface
{
    /**
     * Stop every other process in the container and check that none survived
     *
     * @return string Why the container could not be confirmed clear, empty when it is
     */
    public function reap(): string;
}
