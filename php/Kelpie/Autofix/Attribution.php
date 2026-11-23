<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

/**
 * The part of Kelpie that wrote a commit or a note, and how it says so
 *
 * A commit gets an `Assisted-by: Kelpie <agent> (<model>)` trailer, next to the `Kelpie-Autofix` marker the loop
 * guard reads. A note's usage footer opens with `Kelpie · agent <agent> · model <model>`. The model is the one the run
 * used, taken from the call itself.
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
        return str_starts_with($line, '_Model ') ? '_Kelpie · agent `' . $this->value . '` · model ' . substr($line, 7) : $line;
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
