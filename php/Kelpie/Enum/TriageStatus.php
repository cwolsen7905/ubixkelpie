<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * Where Kelpie is with a merge request, as the scoped `kelpie::…` label the MR list shows
 *
 * `Working` is on while a run is in progress; a finished run leaves exactly one of the other three.
 * When several threads ended differently the most demanding wins: `NeedsYou`, then `Proposed`, then `Pushed`.
 *
 * @see \Kelpie\Tests\Enum\TriageStatusTest PHPUnit test case
 */
enum TriageStatus: string
{
    case Working  = 'kelpie::working';
    case Pushed   = 'kelpie::pushed';
    case Proposed = 'kelpie::proposed';
    case NeedsYou = 'kelpie::needs-you';

    /**
     * The label an earlier version used as its claim; still read as one, never written
     */
    public const string LEGACY_CLAIM = 'triage: working';

    /**
     * The labels earlier versions left on merge requests: taken off whenever a status label is set, never written
     */
    public const array LEGACY = ['triage: working', 'triage: pushed', 'triage: proposed', 'triage: needs you', 'kelpie::needs-human'];

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
     * Every label that is not the one given: what to take off so the scoped set stays exclusive
     *
     * @param ?self $keep The status to keep, null to keep none
     *
     * @return list<string> The other statuses and the legacy labels
     */
    public static function othersThan(?self $keep): array
    {
        $others = self::LEGACY;
        foreach (self::cases() as $status) {
            if ($status !== $keep) {
                $others[] = $status->value;
            }
        }

        return $others;
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
