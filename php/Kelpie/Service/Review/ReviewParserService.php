<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\Severity;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Turns the model's reply into a validated review, or names what was wrong with it so the retry can say so
 *
 * The reply may be wrapped in a fence or surrounded by prose: every top-level balanced `{...}` span that parses
 * is a candidate, outermost and earliest first, and the first that matches the schema wins.
 *
 * @see \Kelpie\Tests\Service\Review\ReviewParserServiceTest PHPUnit test case
 */
final class ReviewParserService
{
    /**
     * Constructor
     *
     * @param Logger      $logger      Logger
     * @param JsonService $jsonService JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly JsonService $jsonService,
    ) {
    }

    /**
     * Parse a reply
     *
     * @param string $reply The model's text
     *
     * @return ReviewResult|string The review, or the first schema problem (or that there was no JSON object at all)
     */
    public function parse(string $reply): ReviewResult|string
    {
        $candidates = $this->candidates($reply);
        if ($candidates === []) {
            return 'Your previous reply contained no JSON object I could parse. Reply with ONLY the JSON object described in the schema: no prose, no Markdown fence, no commentary before or after.';
        }

        $problem = '';
        foreach ($candidates as $index => $candidate) {
            $found = $this->validate($candidate);
            if ($found instanceof ReviewResult) {
                return $found;
            }
            if ($index === 0) {
                $problem = $found;
            }
        }

        return 'Your previous reply was JSON but did not match the schema: ' . $problem . '. Reply with ONLY the corrected JSON object, no prose and no fence.';
    }

    /**
     * Every top-level balanced `{...}` span of the text that parses as JSON, as decoded values
     *
     * @param string $text The text
     *
     * @return list<mixed>
     */
    public function candidates(string $text): array
    {
        $out   = [];
        $depth = 0;
        $start = 0;
        $inStr = false;
        $esc   = false;

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{') {
                $start = $depth === 0 ? $i : $start;
                $depth++;
            } elseif ($c === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0) {
                    try {
                        $out[] = $this->jsonService->decode(substr($text, $start, $i - $start + 1));
                    } catch (DtoException $e) {
                        $this->logger->debug('A braced span of the reply is not JSON', ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The stable id of a finding: file and the model's issue slug, never the line or the wording
     *
     * @param string $file        The file
     * @param string $fingerprint The slug
     *
     * @return string
     */
    public function idFor(string $file, string $fingerprint): string
    {
        return substr(sha1($file . '|' . mb_strtolower($fingerprint)), 0, 12);
    }

    /**
     * @param mixed $value One decoded candidate
     *
     * @return ReviewResult|string The review, or the first problem
     */
    private function validate(mixed $value): ReviewResult|string
    {
        if (!is_array($value) || array_is_list($value)) {
            return 'the top level must be a JSON object';
        }
        $verdict = $value['verdict'] ?? null;
        if (!in_array($verdict, ['pass', 'concerns', 'block'], true)) {
            return 'verdict must be one of pass, concerns, block';
        }
        if (!is_string($value['summary'] ?? null)) {
            return 'summary must be a string';
        }
        if (!is_array($value['findings'] ?? null) || !array_is_list($value['findings'])) {
            return 'findings must be an array';
        }

        $findings = [];
        foreach ($value['findings'] as $k => $raw) {
            $finding = $this->toFinding($k, $raw);
            if (is_string($finding)) {
                return $finding;
            }
            $findings[] = $finding;
        }

        return new ReviewResult($verdict, $value['summary'], $findings);
    }

    /**
     * @param int   $k   The finding's position
     * @param mixed $raw The decoded finding
     *
     * @return Finding|string The finding, or the problem
     */
    private function toFinding(int $k, mixed $raw): Finding|string
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return sprintf('findings[%d] must be an object', $k);
        }
        $severity   = is_string($raw['severity'] ?? null) ? Severity::tryFrom($raw['severity']) : null;
        $confidence = is_string($raw['confidence'] ?? null) ? Confidence::tryFrom($raw['confidence']) : null;
        $file       = $raw['file'] ?? null;
        $title      = $raw['title'] ?? null;
        $print      = $raw['fingerprint'] ?? null;
        $line       = $raw['line'] ?? null;

        return match (true) {
            $severity === null                           => sprintf('findings[%d].severity must be one of blocker, major, minor, nit', $k),
            $confidence === null                         => sprintf('findings[%d].confidence must be one of high, medium, low', $k),
            !is_string($file) || $file === ''            => sprintf('findings[%d].file must be a non-empty string', $k),
            $line !== null && !is_int($line) && !is_float($line) => sprintf('findings[%d].line must be a number or null', $k),
            !is_string($title) || $title === ''          => sprintf('findings[%d].title must be a non-empty string', $k),
            !is_string($raw['why'] ?? null)              => sprintf('findings[%d].why must be a string', $k),
            !is_string($print) || $print === ''          => sprintf('findings[%d].fingerprint must be a non-empty string', $k),
            default                                      => new Finding($severity, $confidence, $file, (int) floor((float) ($line ?? 0)), $title, $raw['why'], $print, $this->idFor($file, $print)),
        };
    }
}
