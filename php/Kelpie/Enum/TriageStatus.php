<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * Where Kelpie is with a merge request, as the `triage: …` label the MR list shows
 *
 * `Working` is on while a run is in progress; a finished run leaves exactly one of the other three.
 * When several threads ended differently the most demanding wins: `NeedsYou`, then `Proposed`, then `Pushed`.
 *
 * @see \Kelpie\Tests\Enum\TriageStatusTest PHPUnit test case
 */
enum TriageStatus: string
{
    case Working  = 'triage: working';
    case Pushed   = 'triage: pushed';
    case Proposed = 'triage: proposed';
    case NeedsYou = 'triage: needs you';

    /**
     * The label an earlier version used as its claim; still read as one, never written
     */
    public const string LEGACY_CLAIM = 'kelpie::working';

    /**
     * The label that switches triage off for one merge request
     */
    public const string OFF = 'triage:off';

    /**
     * The colour the label is created with
     *
     * @return string
     */
    public function colour(): string
    {
        return match ($this) {
            self::Working  => '#6699cc',
            self::Pushed   => '#2da160',
            self::Proposed => '#e6a700',
            self::NeedsYou => '#d9534f',
        };
    }

    /**
     * How demanding the status is: the higher one wins when a run ends with threads in several
     *
     * @return int
     */
    public function rank(): int
    {
        return match ($this) {
            self::Working  => 0,
            self::Pushed   => 1,
            self::Proposed => 2,
            self::NeedsYou => 3,
        };
    }

    /**
     * The three labels a finished run leaves
     *
     * @return list<self>
     */
    public static function outcomes(): array
    {
        return [self::Pushed, self::Proposed, self::NeedsYou];
    }
}
