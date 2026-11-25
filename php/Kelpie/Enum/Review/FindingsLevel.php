<?php

declare(strict_types=1);

namespace Kelpie\Enum\Review;

/**
 * The worst review finding still open on a merge request, as the `kelpie-findings::…` label the MR list shows
 *
 * A merge request carries one at a time; the review keeps the set exclusive itself.
 *
 * @see \Kelpie\Tests\Enum\Review\FindingsLevelTest PHPUnit test case
 */
enum FindingsLevel: string
{
    case Blocker   = 'kelpie-findings::blocker';
    case Major     = 'kelpie-findings::major';
    case MinorOnly = 'kelpie-findings::minor-only';
    case None      = 'kelpie-findings::none';

    /**
     * The level for the worst severity open, None when nothing is
     *
     * @param ?Severity $worst The worst open severity, null when none is open
     *
     * @return self
     */
    public static function fromWorst(?Severity $worst): self
    {
        return match ($worst) {
            Severity::Blocker              => self::Blocker,
            Severity::Major                => self::Major,
            Severity::Minor, Severity::Nit => self::MinorOnly,
            null                           => self::None,
        };
    }

    /**
     * The colour the label is created with
     *
     * @return string
     */
    public function colour(): string
    {
        return match ($this) {
            self::Blocker   => '#d9534f',
            self::Major     => '#ed9121',
            self::MinorOnly => '#e6c700',
            self::None      => '#2da160',
        };
    }

    /**
     * What the label means, as its description in GitLab
     *
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::Blocker   => 'An open Kelpie review thread is a blocker',
            self::Major     => 'The worst open Kelpie review thread is a major',
            self::MinorOnly => 'Only minor or nit Kelpie review threads are open',
            self::None      => 'No Kelpie review threads are open',
        };
    }

    /**
     * Every level label that is not the one given: what to take off so the set stays exclusive
     *
     * @param self $keep The level to keep
     *
     * @return list<string>
     */
    public static function othersThan(self $keep): array
    {
        $others = [];
        foreach (self::cases() as $level) {
            if ($level !== $keep) {
                $others[] = $level->value;
            }
        }

        return $others;
    }
}
