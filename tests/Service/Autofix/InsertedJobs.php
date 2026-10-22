<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Countable;

/**
 * The parameters of the jobs a test's SQL stub saw inserted, in order
 */
final class InsertedJobs implements Countable
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $jobs = [];

    /**
     * Note one inserted job
     *
     * @param array<string, mixed> $parameters The bound parameters of the INSERT
     *
     * @return void
     */
    public function add(array $parameters): void
    {
        $this->jobs[] = $parameters;
    }

    /**
     * The parameters of the nth inserted job
     *
     * @param int $index From 0
     *
     * @return array<string, mixed> Empty when there is no such job
     */
    public function get(int $index): array
    {
        return $this->jobs[$index] ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count($this->jobs);
    }
}
