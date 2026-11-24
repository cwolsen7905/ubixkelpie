<?php

declare(strict_types=1);

namespace Kelpie\Settings;

use Kelpie\DataTransferObject\Project;
use Kelpie\DataTransferObject\ProjectSettingsPatch;
use Kelpie\Enum\ProjectMode;
use Kelpie\Settings\Exception\SettingsValidationException;
use Psr\Log\LoggerInterface as Logger;

/**
 * The rules a settings patch must meet, in one place
 *
 * The body is validated whole against the project's current values, so "a hard cap is at least its soft cap" is
 * checked on the resulting pair even when the patch sets only one of them. The result holds only what changes the row.
 * Unknown keys are refused, so a typo is not silently dropped; `gate_command` and `protected_paths` are not settable
 * here because they widen what the fixer may run or touch.
 *
 * Soft caps are never null (the column is NOT NULL); a hard cap of null is unlimited; a model of null is the default.
 *
 * @see \Kelpie\Tests\Settings\ProjectSettingsValidatorTest PHPUnit test case
 */
final class ProjectSettingsValidator
{
    public const int MAX_COUNT        = 1000000;
    public const int MAX_MODEL_LENGTH = 100;
    public const int MAX_COST_CENTS   = 999999;

    /**
     * Each cap: its kind, the column of its soft limit and of its hard limit (null when no column holds one yet)
     */
    private const array CAPS = [
        'max_cost_usd_day'          => ['cost', 'max_cost_usd_day', 'max_cost_usd_day_hard'],
        'max_fix_commits_per_mr'    => ['count', 'max_fix_commits_per_mr', null],
        'max_issues_per_author_day' => ['count', 'max_issues_per_author_day', 'max_issues_per_author_day_hard'],
        'max_issues_per_day'        => ['count', 'max_issues_per_day', 'max_issues_per_day_hard'],
    ];

    private const array MODELS = ['triage_model', 'review_model'];

    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first, and the standards test enforces it; this one has nothing to log)
    ) {
    }

    /**
     * Validate a decoded patch body against a project
     *
     * @param mixed   $body    The decoded JSON body
     * @param Project $current The project as it is now
     *
     * @return ProjectSettingsPatch Only the columns that differ from `$current`
     *
     * @throws SettingsValidationException When any rule is broken
     */
    public function validate(mixed $body, Project $current): ProjectSettingsPatch
    {
        if (!is_array($body) || !$this->isObject($body)) {
            throw new SettingsValidationException('The settings patch is not valid', 0, [['field' => '(body)', 'message' => 'must be a JSON object']]);
        }

        $errors  = $this->unknownKeys($body, ['mode', 'settings'], '');
        $changes = [];

        if (array_key_exists('mode', $body)) {
            $mode = is_string($body['mode']) ? ProjectMode::tryFrom($body['mode']) : null;
            if ($mode === null) {
                $errors[] = ['field' => 'mode', 'message' => 'must be one of off, propose, push'];
            } else {
                $changes['mode'] = $mode;
            }
        }

        if (array_key_exists('settings', $body)) {
            if (is_array($body['settings']) && $this->isObject($body['settings'])) {
                [$settingChanges, $settingErrors] = $this->settings($body['settings'], $current);
                $changes                          = [...$changes, ...$settingChanges];
                $errors                           = [...$errors, ...$settingErrors];
            } else {
                $errors[] = ['field' => 'settings', 'message' => 'must be a JSON object'];
            }
        }

        if ($errors !== []) {
            throw new SettingsValidationException('The settings patch is not valid', 0, $errors);
        }

        $differing = [];
        foreach ($changes as $column => $value) {
            if ($this->currentValue($current, $column) !== $value) {
                $differing[$column] = $value;
            }
        }

        return new ProjectSettingsPatch($differing);
    }

    /**
     * @param mixed $value A decoded JSON value
     *
     * @return bool Whether it is a JSON object (an empty one decodes to an empty array)
     */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * @param array<mixed> $settings The `settings` object
     * @param Project      $current  The project now
     *
     * @return array{array<string, ProjectMode|int|string|null>, list<array{field: string, message: string}>} The changes and the broken rules
     */
    private function settings(array $settings, Project $current): array
    {
        $errors  = $this->unknownKeys($settings, [...self::MODELS, ...array_keys(self::CAPS)], 'settings.');
        $changes = [];

        foreach (self::MODELS as $model) {
            if (!array_key_exists($model, $settings)) {
                continue;
            }
            $value = $settings[$model];
            if ($value === null) {
                $changes[$model] = null;
            } elseif (is_string($value) && $value !== '' && strlen($value) <= self::MAX_MODEL_LENGTH && preg_match('/^\S+$/', $value) === 1) {
                $changes[$model] = $value;
            } else {
                $errors[] = ['field' => 'settings.' . $model, 'message' => 'must be null (the default) or a model id of 1 to ' . self::MAX_MODEL_LENGTH . ' characters without whitespace'];
            }
        }

        foreach (self::CAPS as $name => [$kind, $softColumn, $hardColumn]) {
            if (!array_key_exists($name, $settings)) {
                continue;
            }
            $cap = $settings[$name];
            if (!is_array($cap) || !$this->isObject($cap)) {
                $errors[] = ['field' => 'settings.' . $name, 'message' => 'must be an object with "soft" and/or "hard"'];
                continue;
            }
            $errors = [...$errors, ...$this->unknownKeys($cap, ['soft', 'hard'], 'settings.' . $name . '.')];

            $capChanges   = [];
            $errorsBefore = count($errors);
            if (array_key_exists('soft', $cap)) {
                $result = $this->limit($cap['soft'], $kind, false);
                if ($result['error'] !== null) {
                    $errors[] = ['field' => 'settings.' . $name . '.soft', 'message' => $result['error']];
                } else {
                    $capChanges[$softColumn] = $result['value'];
                }
            }
            if (array_key_exists('hard', $cap)) {
                if ($hardColumn === null) {
                    if ($cap['hard'] !== null) {
                        $errors[] = ['field' => 'settings.' . $name . '.hard', 'message' => 'a hard limit cannot be set for this cap yet; it is always null (unlimited)'];
                    }
                } else {
                    $result = $this->limit($cap['hard'], $kind, true);
                    if ($result['error'] !== null) {
                        $errors[] = ['field' => 'settings.' . $name . '.hard', 'message' => $result['error']];
                    } else {
                        $capChanges[$hardColumn] = $result['value'];
                    }
                }
            }

            // A cap that already has an error is not compared: the pair is not known, and a second error would mislead.
            $error = count($errors) === $errorsBefore ? $this->hardBelowSoft($kind, $softColumn, $hardColumn, $current, $capChanges) : null;
            if ($error !== null) {
                $errors[] = ['field' => 'settings.' . $name . '.hard', 'message' => $error];
            }
            $changes = [...$changes, ...$capChanges];
        }

        return [$changes, $errors];
    }

    /**
     * Validate one soft or hard limit
     *
     * @param mixed  $value    The value from the body
     * @param string $kind     Either count or cost
     * @param bool   $nullable Whether null (unlimited) is allowed
     *
     * @return array{value: int|string|null, error: ?string} The value to store, or what is wrong with it
     */
    private function limit(mixed $value, string $kind, bool $nullable): array
    {
        if ($value === null) {
            return $nullable ? ['value' => null, 'error' => null] : ['value' => null, 'error' => 'a soft limit always has a number and cannot be null'];
        }

        $orNull = $nullable ? ', or null for unlimited' : '';

        if ($kind === 'count') {
            return is_int($value) && $value >= 1 && $value <= self::MAX_COUNT ? ['value' => $value, 'error' => null] : ['value' => null, 'error' => 'must be a whole number from 1 to ' . self::MAX_COUNT . $orNull];
        }

        $cents = is_string($value) ? $this->cents($value) : null;
        if ($cents === null || $cents < 1 || $cents > self::MAX_COST_CENTS) {
            return ['value' => null, 'error' => 'must be a decimal string such as "2.00" (at most 2 places, above 0, at most 9999.99)' . $orNull];
        }

        return ['value' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100), 'error' => null];
    }

    /**
     * A hard limit below its soft limit could never let the soft warning fire
     *
     * @param string                                     $kind       Either count or cost
     * @param string                                     $softColumn The soft column
     * @param ?string                                    $hardColumn The hard column, if there is one
     * @param Project                                    $current    The project now
     * @param array<string, ProjectMode|int|string|null> $changes    Values this cap's patch sets
     *
     * @return ?string What is wrong, or null
     */
    private function hardBelowSoft(string $kind, string $softColumn, ?string $hardColumn, Project $current, array $changes): ?string
    {
        if ($hardColumn === null || ($changes === [])) {
            return null;
        }
        $soft = array_key_exists($softColumn, $changes) ? $changes[$softColumn] : $this->currentValue($current, $softColumn);
        $hard = array_key_exists($hardColumn, $changes) ? $changes[$hardColumn] : $this->currentValue($current, $hardColumn);
        if ($hard === null || $soft === null || $soft instanceof ProjectMode || $hard instanceof ProjectMode) {
            return null;
        }

        $below = $kind === 'cost' ? $this->storedCents((string) $hard) < $this->storedCents((string) $soft) : (int) $hard < (int) $soft;

        return $below ? 'must be at least the soft limit (' . $soft . ')' : null;
    }

    /**
     * @param string $value A stored cost, as the DECIMAL(8,2) column holds it
     *
     * @return int Whole cents; up to 6 whole digits, so any value the column can hold
     */
    private function storedCents(string $value): int
    {
        if (preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * @param string $value A candidate decimal string
     *
     * @return ?int Whole cents, or null when it is not a decimal with at most 2 places and at most 4 whole digits
     */
    private function cents(string $value): ?int
    {
        if (preg_match('/^(\d{1,4})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * @param Project $current The project now
     * @param string  $column  The `projects` column
     *
     * @return ProjectMode|int|string|null
     */
    private function currentValue(Project $current, string $column): ProjectMode|int|string|null
    {
        return match ($column) {
            'mode'                           => $current->mode,
            'triage_model'                   => $current->triageModel,
            'review_model'                   => $current->reviewModel,
            'max_issues_per_day'             => $current->maxIssuesPerDay,
            'max_issues_per_day_hard'        => $current->maxIssuesPerDayHard,
            'max_issues_per_author_day'      => $current->maxIssuesPerAuthorDay,
            'max_issues_per_author_day_hard' => $current->maxIssuesPerAuthorDayHard,
            'max_cost_usd_day'               => $current->maxCostUsdDay,
            'max_cost_usd_day_hard'          => $current->maxCostUsdDayHard,
            'max_fix_commits_per_mr'         => $current->maxFixCommitsPerMr,
            default                          => null,
        };
    }

    /**
     * @param array<mixed> $object  The object to check
     * @param string[]     $allowed Keys it may hold
     * @param string       $prefix  Field name prefix for the error
     *
     * @return list<array{field: string, message: string}> One error per key that is not allowed
     */
    private function unknownKeys(array $object, array $allowed, string $prefix): array
    {
        $errors = [];
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = ['field' => $prefix . $key, 'message' => 'is not a setting this API knows'];
            }
        }

        return $errors;
    }
}
