<?php

declare(strict_types=1);

namespace Kelpie\Enum\Review;

/**
 * Where the AI review is with a merge request, as the `kelpie-review::…` label the MR list shows
 *
 * A merge request carries one at a time: setting one takes the others off. `Reviewing` is on while the model runs;
 * every finished run leaves one of the others. GitLab's free plan does not enforce scoped labels, so the review keeps
 * the set exclusive itself.
 *
 * @see \Kelpie\Tests\Enum\Review\ReviewStatusTest PHPUnit test case
 */
enum ReviewStatus: string
{
    case Reviewing  = 'kelpie-review::reviewing';
    case Reviewed   = 'kelpie-review::reviewed';
    case Clean      = 'kelpie-review::clean';
    case LastRound  = 'kelpie-review::last-round';
    case HardCap    = 'kelpie-review::hard-cap';
    case Skipped    = 'kelpie-review::skipped';
    case Unreviewed = 'kelpie-review::unreviewed';

    /**
     * The colour the label is created with
     *
     * @return string
     */
    public function colour(): string
    {
        return match ($this) {
            self::Reviewing  => '#6699cc',
            self::Reviewed   => '#e6a700',
            self::Clean      => '#2da160',
            self::LastRound  => '#ed9121',
            self::HardCap    => '#d9534f',
            self::Skipped    => '#a0a0a0',
            self::Unreviewed => '#8e44ad',
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
            self::Reviewing  => 'Kelpie AI review is running on the latest commit',
            self::Reviewed   => 'Kelpie AI review ran; threads are open',
            self::Clean      => 'Kelpie AI review ran; no review threads are open',
            self::LastRound  => 'Kelpie AI review used its last automatic round; the next push is not reviewed without review:extra',
            self::HardCap    => 'Kelpie AI review stopped at its round limit; a person must review',
            self::Skipped    => 'Kelpie AI review skipped this commit (docs only or nothing reviewable)',
            self::Unreviewed => 'Kelpie AI review could not run or the diff is too large; a person must review',
        };
    }

    /**
     * Every status label that is not the one given: what to take off so the set stays exclusive
     *
     * @param self $keep The status to keep
     *
     * @return list<string>
     */
    public static function othersThan(self $keep): array
    {
        $others = [];
        foreach (self::cases() as $status) {
            if ($status !== $keep) {
                $others[] = $status->value;
            }
        }

        return $others;
    }
}
