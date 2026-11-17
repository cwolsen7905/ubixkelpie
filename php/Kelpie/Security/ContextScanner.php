<?php

declare(strict_types=1);

namespace Kelpie\Security;

use Psr\Log\LoggerInterface as Logger;

/**
 * Checks forge and repo text before it reaches a model
 *
 * Looks for hidden HTML comments, invisible and bidirectional Unicode, instruction-override phrasing and
 * reads of secret paths. A hit never stops a run: text comes back cleaned (so it can be quoted as data) and the
 * finding is logged; a repo guide with any hit is dropped whole by `guide()`. The scan is a tripwire, not the
 * control: the prompts still fence the text as data, and the run still has no token and no shell.
 *
 * HTML comments are the one place the scanner must not trip over its own side: Kelpie writes markers as comments.
 * A comment is left alone only when the caller says Kelpie's own bot wrote the text and the comment is exactly one
 * of the marker shapes below. The same marker typed by anyone else is a hit.
 *
 * @see \Kelpie\Tests\Security\ContextScannerTest PHPUnit test case
 */
final class ContextScanner
{
    private const int EXCERPT_LENGTH = 60;

    private const string INVISIBLE = '/[\x{00AD}\x{180E}\x{200B}-\x{200D}\x{2060}-\x{2064}\x{FEFF}\x{E0000}-\x{E007F}]/u';

    private const string BIDI = '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private const array PHRASES = [
        '/\b(?:ignore|disregard|forget|override|bypass)\s+(?:(?:all|any|every|of|the)\s+)*(?:your|my|previous|prior|above|earlier|preceding|system|safety|all|any)\s+(?:(?:previous|prior|above|earlier|preceding|system|safety|your)\s+)*(?:instructions?|rules|prompts?|guidelines|directions|constraints|restrictions)\b/i',
        '/\byou\s+are\s+now\s+(?:in\s+)?(?:dan|developer\s+mode|unrestricted|jailbroken|an?\s+unrestricted)\b/i',
        '/\bnew\s+(?:system\s+)?instructions?\s*:/i',
        '/\b(?:reveal|print|show|output|repeat|leak|disclose)\s+(?:me\s+)?(?:your|the)\s+(?:system\s+)?(?:prompt|instructions)\b/i',
        '/\bact\s+as\s+(?:if\s+you\s+(?:have|had)\s+no|though\s+you\s+(?:have|had)\s+no)\s+(?:rules|restrictions|limits)\b/i',
    ];

    private const string SECRET_READ = '#\b(?:cat|read|print|show|open|dump|send|upload|post|exfiltrate|copy|cp|less|head|tail|base64|curl|echo)\b[^\n]{0,60}?(?:\.env\b|\bid_(?:rsa|dsa|ecdsa|ed25519)\b|\.pem\b|/etc/(?:passwd|shadow)\b|\.ssh/|\.aws/credentials|\.npmrc\b|\.netrc\b|\.kube/config|vault[-_ ]?token|\bsecrets?\.(?:ya?ml|json|env)\b)#i';

    /**
     * The shapes of the comments Kelpie and its review write: a name, then only key=value words, no markup or prose.
     * They carry no signature, so a match proves nothing alone: it counts only in a note Kelpie's own bot wrote.
     */
    private const string MARKER = '/^<!-- (?:kelpie:(?:claim|release|reply|summary|cap-held|pipeline)|ai-review:(?:fp=[0-9a-f]{6,64}|summary))(?: [A-Za-z0-9_.-]{1,24}=[A-Za-z0-9_.:+-]{0,64}){0,6} ?-->$/D';

    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * Scan text and return it cleaned, with what was found
     *
     * @param string $text       The untrusted text
     * @param string $source     Where it came from, for the log (`mr:12 description`, `note 345`, ...)
     * @param bool   $fromKelpie Whether Kelpie's own bot wrote it, so that its markers may stay
     *
     * @return array{text: string, kinds: ScanKind[]} The cleaned text, and the kinds found (each once); no kinds for clean text
     */
    public function scan(string $text, string $source, bool $fromKelpie = false): array
    {
        $text  = mb_scrub($text);
        $parts = preg_split('/(<!--.*?(?:-->|$))/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $clean = '';
        $found = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0 || ($fromKelpie && preg_match(self::MARKER, $part) === 1)) {
                $clean .= $part;

                continue;
            }
            $found[] = [ScanKind::HtmlComment, $this->excerpt($part)];
            $clean  .= str_replace(['<!--', '-->'], ['<! --', '-- >'], $part);
        }

        foreach ([[self::INVISIBLE, ScanKind::InvisibleChar], [self::BIDI, ScanKind::BidiControl]] as [$pattern, $kind]) {
            $count = preg_match_all($pattern, $clean);
            if ($count > 0) {
                $found[] = [$kind, $count . ' character(s)'];
                $clean   = (string) preg_replace($pattern, '', $clean);
            }
        }

        $flat = (string) preg_replace('/\s+/', ' ', $clean);
        foreach (self::PHRASES as $pattern) {
            if (preg_match($pattern, $flat, $m) === 1) {
                $found[] = [ScanKind::InjectionPhrase, $this->excerpt($m[0])];
            }
        }
        if (preg_match(self::SECRET_READ, $clean, $m) === 1) {
            $found[] = [ScanKind::SecretPath, $this->excerpt($m[0])];
        }

        $kinds = [];
        $lines = [];
        foreach ($found as [$kind, $excerpt]) {
            $lines[] = $kind->value . ': ' . $excerpt;
            if (!in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }
        if ($lines !== []) {
            $this->logger->warning('Context scan hit', ['source' => $source, 'findings' => $lines]);
        }

        return ['text' => $clean, 'kinds' => $kinds];
    }

    /**
     * A repo guide for the model, or null when the scan found anything and the guide is skipped
     *
     * @param string $path The guide's path in the repo
     * @param string $text Its content
     *
     * @return ?string The text unchanged when clean, null when skipped
     */
    public function guide(string $path, string $text): ?string
    {
        if ($this->scan($text, 'guide ' . $path)['kinds'] !== []) {
            $this->logger->warning('Repo guide skipped by the context scan', ['path' => $path]);

            return null;
        }

        return $text;
    }

    /**
     * Whether the kinds found mean the text tries to steer the model (a phrase or a secret path), not just stray characters
     *
     * @param ScanKind[] $kinds What a scan found
     *
     * @return bool
     */
    public function isSuspicious(array $kinds): bool
    {
        return in_array(ScanKind::InjectionPhrase, $kinds, true) || in_array(ScanKind::SecretPath, $kinds, true);
    }

    /**
     * A short printable excerpt, safe for a log line
     *
     * @param string $text The matched text
     *
     * @return string
     */
    private function excerpt(string $text): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', $text);

        return (string) preg_replace('/[^\x20-\x7E]/', '?', mb_substr($flat, 0, self::EXCERPT_LENGTH));
    }
}
