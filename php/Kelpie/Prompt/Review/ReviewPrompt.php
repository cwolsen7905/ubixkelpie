<?php

declare(strict_types=1);

namespace Kelpie\Prompt\Review;

use Psr\Log\LoggerInterface as Logger;

/**
 * The prompt that asks a model to review one merge request: the base prompt and the context message
 *
 * The title, the standards documents and the diff are written by the author, so the base prompt says they are
 * data. The prompt is advice; the read-only tools are the control.
 *
 * @see \Kelpie\Tests\Prompt\Review\ReviewPromptTest PHPUnit test case
 */
final class ReviewPrompt
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first; the prompt has nothing to log)
    ) {
    }

    /**
     * The system message: the base prompt
     *
     * @return string
     */
    public function system(): string
    {
        return (string) file_get_contents(__DIR__ . '/prompt.md');
    }

    /**
     * The user message: title, standards, what is already raised, and the diff
     *
     * @param string $title     The merge request title
     * @param string $standards The standards as Markdown sections, empty when there are none
     * @param string $raised    The findings already raised, one per line, empty when none
     * @param string $diff      The diff
     * @param string $retry     What was wrong with the previous reply, empty on the first attempt
     *
     * @return string
     */
    public function context(string $title, string $standards, string $raised, string $diff, string $retry = ''): string
    {
        $out  = sprintf("# MR title\n\n%s\n\n", $title);
        $out .= "# Repository standards\n\nContext only. Do not re-report what these say a tool already enforces.\n\n";
        $out .= $standards !== '' ? $standards : "(none found)\n\n";
        $out .= "# Already raised on this MR (do not report again)\n\n" . $raised;
        $out .= "\n# Diff\n\n```diff\n" . $diff . "\n```\n";

        return $retry === '' ? $out : $out . "\n" . $retry . "\n";
    }
}
