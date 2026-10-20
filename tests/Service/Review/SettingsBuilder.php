<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewSettings;

/**
 * Builds review settings for the tests: a merge request 3 of project 1 whose diff is the sample diff fixture
 */
final class SettingsBuilder
{
    /**
     * The fixtures directory
     *
     * @return string
     */
    public static function fixtures(): string
    {
        return __DIR__ . '/fixtures';
    }

    /**
     * Settings with some values replaced
     *
     * @param array<string, mixed> $overrides Constructor arguments to replace
     *
     * @return ReviewSettings
     */
    public static function make(array $overrides = []): ReviewSettings
    {
        $labels = [];
        foreach (is_array($overrides['labels'] ?? null) ? $overrides['labels'] : [] as $label) {
            $labels[] = is_string($label) ? $label : '';
        }

        return new ReviewSettings(
            blocking:      self::flag($overrides, 'blocking'),
            shadow:        self::flag($overrides, 'shadow'),
            dryRun:        self::flag($overrides, 'dryRun'),
            model:         self::text($overrides, 'model', ReviewSettings::DEFAULT_MODEL),
            apiModel:      self::text($overrides, 'apiModel', 'anthropic/claude-haiku-5.5'),
            effort:        self::text($overrides, 'effort', ''),
            maxLines:      self::num($overrides, 'maxLines', 3000),
            maxBytes:      self::num($overrides, 'maxBytes', 153600),
            maxRounds:     self::num($overrides, 'maxRounds', 3),
            extraLabel:    self::text($overrides, 'extraLabel', 'review:extra'),
            maxTurns:      self::num($overrides, 'maxTurns', 4),
            apiUrl:        'https://gitlab.test/api/v4',
            token:         self::text($overrides, 'token', 'review-token-not-real'),
            project:       '1',
            mrIid:         self::num($overrides, 'mrIid', 3),
            title:         self::text($overrides, 'title', 'Add the transfer'),
            labels:        $labels,
            baseSha:       self::text($overrides, 'baseSha', ''),
            headSha:       self::text($overrides, 'headSha', 'abcdef1234567890abcdef1234567890abcdef12'),
            repo:          self::text($overrides, 'repo', self::fixtures()),
            summaryMarker: self::flag($overrides, 'shadow') ? '<!-- kelpie-review:shadow' : '<!-- ai-review:summary',
            diffFile:      self::text($overrides, 'diffFile', self::fixtures() . '/sample.diff'),
            cannedFile:    self::text($overrides, 'cannedFile', ''),
            existing:      self::text($overrides, 'existing', ''),
        );
    }

    /**
     * @param array<string, mixed> $overrides The overrides
     * @param string               $key       The key
     * @param string               $default   Used when the key is not overridden
     *
     * @return string
     */
    private static function text(array $overrides, string $key, string $default): string
    {
        return is_string($overrides[$key] ?? null) ? $overrides[$key] : $default;
    }

    /**
     * @param array<string, mixed> $overrides The overrides
     * @param string               $key       The key
     *
     * @return bool
     */
    private static function flag(array $overrides, string $key): bool
    {
        return ($overrides[$key] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $overrides The overrides
     * @param string               $key       The key
     * @param int                  $default   Used when the key is not overridden
     *
     * @return int
     */
    private static function num(array $overrides, string $key, int $default): int
    {
        return is_int($overrides[$key] ?? null) ? $overrides[$key] : $default;
    }
}
