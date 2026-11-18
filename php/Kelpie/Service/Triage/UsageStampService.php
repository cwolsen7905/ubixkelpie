<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

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
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first; formatting has nothing to log)
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
        $cost   = $completion->costUsd === null ? '' : sprintf(', $%.4f (OpenRouter)', $completion->costUsd);

        return sprintf('_Model `%s`, effort default, %s%s._', $model, $tokens, $cost);
    }

    /**
     * A text with the footer under it, or the text as it was when no model call is known
     *
     * @param string      $text       The text
     * @param ?Completion $completion The call behind it
     *
     * @return string
     */
    public function append(string $text, ?Completion $completion): string
    {
        return $completion === null ? $text : $text . "\n\n" . $this->line($completion);
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
