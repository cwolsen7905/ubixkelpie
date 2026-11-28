<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

/**
 * The part of Kelpie that wrote a commit or a note, and how it says so
 *
 * A commit gets an `Assisted-by: Kelpie <agent> (<model>)` trailer, next to the `Kelpie-Autofix` marker the loop
 * guard reads. A note's usage footer opens with `Kelpie <build> · agent <agent> · model <model>`, the build being the
 * image's commit (`signature()`), so a note says which Kelpie wrote it. The model is the one the run used, taken from the
 * call itself.
 *
 * @see \Kelpie\Tests\Autofix\AttributionTest PHPUnit test case
 */
enum Attribution: string
{
    /**
     * The one-shot propose model's edits
     */
    case Propose = 'propose-model';

    /**
     * The fixing agent (the `claude` CLI)
     */
    case Fixer = 'fixer-agent';

    /**
     * The triage model's replies
     */
    case Triage = 'triage-model';

    /**
     * The AI review
     */
    case Review = 'review-agent';

    /**
     * A commit that replays a stored proposal: the proposal's row keeps the model, not which of the two made it
     */
    case Autofix = 'autofix';

    /**
     * The commit trailer
     *
     * @param string $model The model id the run used; empty when it is not known
     *
     * @return string One line, no newline
     */
    public function trailer(string $model): string
    {
        $model = self::word($model);

        return 'Assisted-by: Kelpie ' . $this->value . ($model === '' ? '' : ' (' . $model . ')');
    }

    /**
     * A usage footer line with the attribution in front
     *
     * @param string $line The usage line, as `UsageStampService::line()` writes it (`_Model `x`, ..._`)
     *
     * @return string The line unchanged when it is not in that shape
     */
    public function footer(string $line): string
    {
        return str_starts_with($line, '_Model ') ? '_' . self::signature() . ' · agent `' . $this->value . '` · model ' . substr($line, 7) : $line;
    }

    /**
     * How a note names the Kelpie that wrote it: `Kelpie <short sha>`, or `Kelpie (unknown build)`
     *
     * The build is `APP_VERSION`, which CI passes to the image build as the commit SHA. A hex SHA is cut to its first 7
     * characters; any other value is shown as it is, made safe for a footer. Unset, empty or `unknown` (the image's
     * default when CI passed nothing) reads `(unknown build)`.
     *
     * @param ?string $version The build; null reads `APP_VERSION`
     *
     * @return string
     */
    public static function signature(?string $version = null): string
    {
        $version = self::word($version ?? (string) getenv('APP_VERSION'));
        if ($version === '' || strtolower($version) === 'unknown') {
            return 'Kelpie (unknown build)';
        }

        return 'Kelpie ' . (preg_match('/^([0-9a-f]{7})[0-9a-f]{0,57}$/i', $version, $sha) === 1 ? $sha[1] : $version);
    }

    /**
     * A model id safe in a trailer: no newline, whitespace run, parenthesis or backtick
     *
     * @param string $name The name
     *
     * @return string
     */
    private static function word(string $name): string
    {
        return trim((string) preg_replace('/[\s`()]+/', ' ', $name));
    }
}
