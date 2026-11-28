<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\Autofix\Attribution;
use Kelpie\DataTransferObject\Completion;
use Psr\Log\LoggerInterface as Logger;

/**
 * Writes the usage footer under a model-written comment: model, effort, tokens and cost
 *
 * The same shape as the one an AI review summary ends with. Tokens and cost are what OpenRouter reported; when it
 * reported no cost the footer shows the tokens and leaves the cost out rather than guess one. Kelpie sets no
 * reasoning effort on these calls, so the effort reads `default`.
 *
 * @see \Kelpie\Tests\Service\Triage\UsageStampServiceTest PHPUnit test case
 */
final class UsageStampService
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first, and the standards test enforces it; formatting has nothing to log)
    ) {
    }

    /**
     * The footer line for one model call
     *
     * @param Completion $completion The call
     *
     * @return string
     */
    public function line(Completion $completion): string
    {
        $model  = str_replace('`', '', $completion->model);
        $tokens = sprintf('%s in / %s out', $this->tokens($completion->promptTokens), $this->tokens($completion->completionTokens));
        $cost   = $completion->costUsd === null ? '' : ', ' . $this->cost($completion->costUsd) . ' (OpenRouter)';

        return sprintf('_Model `%s`, effort default, %s%s._', $model, $tokens, $cost);
    }

    /**
     * Two calls behind one reply as one: tokens added, and the cost added only when both calls reported one
     *
     * Either may be missing, and then the other stands alone. The model is the later call's.
     *
     * @param ?Completion $earlier The earlier call
     * @param ?Completion $later   The later call
     *
     * @return ?Completion
     */
    public function sum(?Completion $earlier, ?Completion $later): ?Completion
    {
        if ($earlier === null || $later === null) {
            return $later ?? $earlier;
        }

        $cost = $earlier->costUsd === null || $later->costUsd === null ? null : $earlier->costUsd + $later->costUsd;

        return new Completion($later->text, $later->model, $earlier->promptTokens + $later->promptTokens, $earlier->completionTokens + $later->completionTokens, $cost);
    }

    /**
     * A text with the footer under it, signed by the build and the agent, or the text as it was when no model call is known
     *
     * @param string      $text       The text
     * @param ?Completion $completion The call behind it
     * @param Attribution $agent      The part of Kelpie that made the call
     *
     * @return string
     */
    public function append(string $text, ?Completion $completion, Attribution $agent): string
    {
        return $completion === null ? $text : $text . "\n\n" . $agent->footer($this->line($completion));
    }

    /**
     * A cost in dollars to four places; a real cost that rounds to zero reads `<$0.0001`, not as free
     *
     * @param float $usd The cost
     *
     * @return string
     */
    private function cost(float $usd): string
    {
        return $usd > 0.0 && $usd < 0.00005 ? '<$0.0001' : sprintf('$%.4f', $usd);
    }

    /**
     * A token count: 999 stays, 12300 becomes 12.3k
     *
     * @param int $n The count
     *
     * @return string
     */
    private function tokens(int $n): string
    {
        return $n >= 1000 ? sprintf('%d.%dk', intdiv($n, 1000), intdiv($n % 1000, 100)) : (string) $n;
    }
}
