<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * How far Kelpie may go on one project
 *
 * Ordered: each mode allows what the one before it allows. `Off` is also what a project that has no row gets.
 *
 * @see \Kelpie\Tests\Enum\ProjectModeTest PHPUnit test case
 */
enum ProjectMode: string
{
    case Off     = 'off';
    case Propose = 'propose';
    case Push    = 'push';

    /**
     * Whether this mode reaches at least as far as another
     *
     * @param self $needed The least mode the action needs
     *
     * @return bool
     */
    public function allows(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }

    /**
     * @return int Where the mode sits in the order, from 0
     */
    private function rank(): int
    {
        return match ($this) {
            self::Off     => 0,
            self::Propose => 1,
            self::Push    => 2,
        };
    }
}
