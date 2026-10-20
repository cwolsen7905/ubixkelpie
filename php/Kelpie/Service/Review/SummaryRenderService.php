<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Kelpie\DataTransferObject\Review\ReviewDiff;
use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\DataTransferObject\Review\ReviewTally;
use Kelpie\DataTransferObject\Review\ReviewUsage;
use Psr\Log\LoggerInterface as Logger;

/**
 * Writes what the review posts: finding threads, the summary note in its variants, and the usage stamp
 *
 * Text derived from the model or the diff goes through `neutralize()`: a line starting with `/` is a GitLab
 * quick action (`/merge`, `/approve`), so its slash becomes a character reference.
 *
 * @see \Kelpie\Tests\Service\Review\SummaryRenderServiceTest PHPUnit test case
 */
final class SummaryRenderService
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first; rendering has nothing to log)
    ) {
    }

    /**
     * Defuse GitLab quick actions
     *
     * @param string $text The text
     *
     * @return string
     */
    public function neutralize(string $text): string
    {
        return (string) preg_replace('~^(\s*)/~m', '$1&#47;', $text);
    }

    /**
     * The usage stamp every summary ends with
     *
     * @param ReviewSettings $settings    The settings
     * @param ReviewUsage    $usage       The summed usage
     * @param bool           $modelCalled Whether a model call ran
     *
     * @return string
     */
    public function stamp(ReviewSettings $settings, ReviewUsage $usage, bool $modelCalled): string
    {
        $sha = substr($settings->headSha, 0, 8);
        if (!$modelCalled) {
            return sprintf('_No model call on `%s`: no model, effort, tokens or cost to report._', $sha);
        }

        return sprintf(
            '_Model `%s`, effort %s, %s in / %s out, $%d.%04d (OpenRouter), on `%s`._',
            $settings->model,
            $settings->effort !== '' ? $settings->effort : 'default',
            $this->tokens($usage->tokensIn),
            $this->tokens($usage->tokensOut),
            intdiv($usage->microCost, 1000000),
            intdiv($usage->microCost % 1000000, 100),
            $sha,
        );
    }

    /**
     * A summary note: the marker with round and commit, the body, the stamp
     *
     * @param ReviewSettings $settings The settings
     * @param string         $body     The body
     * @param int            $round    The round
     * @param string         $stamp    The usage stamp
     *
     * @return string
     */
    public function note(ReviewSettings $settings, string $body, int $round, string $stamp): string
    {
        $marker = sprintf('%s round=%d sha=%s -->', $settings->summaryMarker, $round, $settings->headSha);
        $label  = $settings->shadow ? "_Shadow run of the new review engine: for comparison, not a review. It opens no threads._\n\n" : '';

        return $marker . "\n" . $label . $body . "\n\n" . $stamp;
    }

    /**
     * The body of a thread for one finding
     *
     * @param Finding $finding The finding
     * @param string  $headSha The commit under review
     *
     * @return string
     */
    public function threadBody(Finding $finding, string $headSha): string
    {
        return $this->neutralize(sprintf(
            "**%s** (%s confidence): %s\n\n`%s:%d`\n\n%s\n\n_Fix and resolve this thread, or resolve it with a reply starting `Dismissed: <reason>`._\n<!-- ai-review:fp=%s sha=%s -->",
            $finding->severity->value,
            $finding->confidence->value,
            $finding->title,
            $finding->file,
            $finding->line,
            $finding->why,
            $finding->id,
            $headSha,
        ));
    }

    /**
     * One finding as a summary line, for findings that open no thread
     *
     * @param Finding $finding The finding
     * @param bool    $withWhy Whether to add the model's reasoning
     *
     * @return string
     */
    public function lineFor(Finding $finding, bool $withWhy): string
    {
        $where = $finding->line > 0 ? sprintf('%s:%d', $finding->file, $finding->line) : $finding->file;
        if (!$withWhy) {
            return $this->neutralize(sprintf('- `%s`: %s', $where, $finding->title));
        }

        return $this->neutralize(sprintf(
            '- `%s`: **%s** (%s): %s. %s',
            $where,
            $finding->severity->value,
            $finding->confidence->value,
            $finding->title,
            trim((string) preg_replace('~\s*\n\s*~', ' ', $finding->why)),
        ));
    }

    /**
     * The summary of a review that found something
     *
     * @param ReviewSettings $settings The settings
     * @param ReviewResult   $result   The review
     * @param ReviewTally    $tally    What posting did
     * @param int            $round    The round
     *
     * @return string
     */
    public function noteWithFindings(ReviewSettings $settings, ReviewResult $result, ReviewTally $tally, int $round): string
    {
        $counts = [];
        foreach ($result->findings as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $severity => $n) {
            $parts[] = $n . ' ' . $severity;
        }

        $out  = sprintf("**AI review: %s**, round %d of %d (`%s` mode)\n\n%s\n\n", $result->verdict, $round, $settings->maxRounds, $this->modeName($settings), $this->neutralize($result->summary));
        $out .= sprintf("Findings: %s. New threads: %d. Already open: %d. Dismissed: %d.\n", $parts === [] ? 'none' : implode(', ', $parts), $tally->posted, $tally->stillOpen, $tally->dismissed);
        if ($tally->fileLevel !== []) {
            $out .= "\nNot tied to one line (no thread; this note is updated on each push):\n" . implode("\n", $tally->fileLevel) . "\n";
        }
        if ($tally->listed !== []) {
            $out .= "\nNew findings (a shadow run opens no threads):\n" . implode("\n", $tally->listed) . "\n";
        }
        if ($tally->sameLine !== []) {
            $out .= "\nNot opened as new threads (the line already has an open thread; see it):\n" . implode("\n", $tally->sameLine) . "\n";
        }
        if ($tally->stale !== []) {
            $out .= "\nOpen threads whose lines changed since they were posted and that this run no longer reports (fixed? resolve them if so):\n" . implode("\n", $tally->stale) . "\n";
        }

        return $out . sprintf("\n_Model `%s` on `%s`. Beside `code:review`, not instead of it. Resolve a thread with `Dismissed: <reason>` to override a finding._", $settings->model, substr($settings->headSha, 0, 8));
    }

    /**
     * The note of a review that found nothing: a plain note, so there is no thread to resolve
     *
     * @param ReviewSettings $settings The settings
     * @param ReviewResult   $result   The review
     * @param ReviewDiff     $diff     The diff
     * @param int            $round    The round
     *
     * @return string
     */
    public function clean(ReviewSettings $settings, ReviewResult $result, ReviewDiff $diff, int $round): string
    {
        $files = '';
        foreach (array_slice($diff->files, 0, 20) as $file) {
            $files .= sprintf("- `%s`\n", $file);
        }
        if (count($diff->files) > 20) {
            $files .= sprintf("- ... and %d more\n", count($diff->files) - 20);
        }

        return sprintf(
            "**AI review: ran, no findings**, round %d of %d (`%s` mode)\n\n%s\n\nModel `%s` reviewed commit `%s`. Files covered:\n%s\n_Beside `code:review`, not instead of it. This note is updated on each push._",
            $round,
            $settings->maxRounds,
            $this->modeName($settings),
            $this->neutralize($result->summary),
            $settings->model,
            $settings->headSha,
            $this->neutralize($files),
        );
    }

    /**
     * @param ReviewSettings $settings The settings
     *
     * @return string
     */
    private function modeName(ReviewSettings $settings): string
    {
        return $settings->blocking ? 'blocking' : 'advisory';
    }

    /**
     * A token count for the stamp: 999 stays, 12300 becomes 12.3k
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
