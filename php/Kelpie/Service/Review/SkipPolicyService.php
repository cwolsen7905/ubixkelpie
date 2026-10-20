<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewDiff;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Psr\Log\LoggerInterface as Logger;

/**
 * Decides, before any model call, whether a review runs: drafts, empty and docs-only diffs, the size cap and the round cap
 *
 * @see \Kelpie\Tests\Service\Review\SkipPolicyServiceTest PHPUnit test case
 */
final class SkipPolicyService
{
    private const string DOCS_PATTERN = '~(\.(md|markdown|rst|txt|adoc)|(^|/)LICENSE[^/]*|(^|/)CHANGELOG[^/]*)$~';

    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first; the policy has nothing to log)
    ) {
    }

    /**
     * Whether the title marks a draft (`Draft:` or `WIP:`)
     *
     * @param string $title The merge request title
     *
     * @return bool
     */
    public function isDraft(string $title): bool
    {
        return preg_match('~^\s*(draft|wip)[: ]~i', $title) === 1;
    }

    /**
     * Why the diff is not reviewed, null when it is
     *
     * @param ReviewDiff $diff The diff
     *
     * @return ?string
     */
    public function skipReason(ReviewDiff $diff): ?string
    {
        if ($diff->files === []) {
            return 'nothing reviewable changed (lockfiles, vendored and generated files are not sent)';
        }

        foreach ($diff->files as $file) {
            if (preg_match(self::DOCS_PATTERN, $file) !== 1) {
                return null;
            }
        }

        return 'only docs changed';
    }

    /**
     * The "too large" note's numbers when the diff is over a cap, null when it is not
     *
     * @param ReviewSettings $settings The settings
     * @param ReviewDiff     $diff     The diff
     *
     * @return ?string
     */
    public function tooLarge(ReviewSettings $settings, ReviewDiff $diff): ?string
    {
        $lines = substr_count($diff->text, "\n");
        $bytes = strlen($diff->text);
        if ($lines <= $settings->maxLines && $bytes <= $settings->maxBytes) {
            return null;
        }

        return sprintf('The reviewable diff is %d lines / %d bytes (limit %d lines / %d bytes).', $lines, $bytes, $settings->maxLines, $settings->maxBytes);
    }

    /**
     * Whether the merge request is past its rounds and has no label that allows more
     *
     * @param ReviewSettings $settings The settings
     * @param int            $round    This run's round
     *
     * @return bool
     */
    public function roundsExhausted(ReviewSettings $settings, int $round): bool
    {
        return $round > $settings->maxRounds && !in_array($settings->extraLabel, $settings->labels, true);
    }
}
