<?php

declare(strict_types=1);

namespace Kelpie\Triage;

use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\TriageClass;
use Psr\Log\LoggerInterface as Logger;

/**
 * The hard limits on a "safe" fix, enforced in code after the model has answered
 *
 * Any violation (size, files, protected paths, tests, attempts) downgrades a `fix` verdict to
 * `needs_human`. The prompt is never the control. Other classes pass through unchanged.
 *
 * @see \Kelpie\Tests\Triage\SafetyPolicyTest PHPUnit test case
 */
final class SafetyPolicy
{
    private const int MAX_CHANGED_LINES = 80;

    private const int MAX_FILES = 5;

    private const int MAX_ATTEMPTS_PER_THREAD = 2;

    private const int MAX_FIX_COMMITS_PER_MR = 3;

    /**
     * Branches the bot may push to
     */
    private const array TARGET_BRANCHES = ['dev', 'staging'];

    /**
     * Paths a fix must never touch, matched against the repo-relative path
     */
    private const array FORBIDDEN_PATTERNS = [
        '#^\.gitlab-ci\.yml$#',
        '#^ci/#',
        '#(^|/)\.env(\.|$)#',
        '#(^|/)(secrets?|credentials?)([./_-]|$)#i',
        '#\.(pem|key|p12|pfx|jks)$#i',
        '#(^|/)id_(rsa|dsa|ecdsa|ed25519)#',
        '#^sql/migrations/#',
        '#(^|/)(composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml|Gemfile\.lock|poetry\.lock)$#',
        '#^app/[^/]+/k8s/#',
        '#(^|/)(deploy|promote)[^/]*\.sh$#',
        '#(^|/)phpcs[^/]*\.xml(\.dist)?$#',
        '#(^|/)phpstan[^/]*\.(neon|dist|baseline)#',
        '#(^|/)phpunit[^/]*\.xml(\.dist)?$#',
    ];

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
     * Apply the policy to a verdict
     *
     * Without a proposal (triage only) a `fix` verdict stands: nothing has been produced to check yet.
     *
     * @param TriageVerdict $verdict  What the model decided
     * @param ?FixProposal  $proposal The facts of the proposed fix, when there is one
     *
     * @return TriageVerdict The verdict, or `needs_human` with the first violated limit as the reason
     */
    public function apply(TriageVerdict $verdict, ?FixProposal $proposal): TriageVerdict
    {
        if ($verdict->class !== TriageClass::Fix || $proposal === null) {
            return $verdict;
        }

        $violation = $this->violation($proposal);
        if ($violation === null) {
            return $verdict;
        }

        $this->logger->info('Triage: fix downgraded to needs_human', ['violation' => $violation]);

        return new TriageVerdict(TriageClass::NeedsHuman, 'Not safe to fix automatically: ' . $violation . '.');
    }

    /**
     * The first violated limit, or null when the proposal is within all of them
     *
     * @param FixProposal $proposal The proposed fix
     *
     * @return ?string
     */
    private function violation(FixProposal $proposal): ?string
    {
        $violation = null;

        if ($proposal->draft) {
            $violation = 'the merge request is a draft';
        } elseif (!in_array($proposal->targetBranch, self::TARGET_BRANCHES, true)) {
            $violation = 'the target branch `' . $proposal->targetBranch . '` is not allowed';
        } elseif ($proposal->threadAttempts >= self::MAX_ATTEMPTS_PER_THREAD) {
            $violation = 'the thread has used its ' . self::MAX_ATTEMPTS_PER_THREAD . ' fix attempts';
        } elseif ($proposal->mrFixCommits >= self::MAX_FIX_COMMITS_PER_MR) {
            $violation = 'the merge request has used its ' . self::MAX_FIX_COMMITS_PER_MR . ' fix commits';
        } elseif ($proposal->changedLines > self::MAX_CHANGED_LINES) {
            $violation = 'the fix changes ' . $proposal->changedLines . ' lines (limit ' . self::MAX_CHANGED_LINES . ')';
        } elseif (count($proposal->paths) > self::MAX_FILES) {
            $violation = 'the fix touches ' . count($proposal->paths) . ' files (limit ' . self::MAX_FILES . ')';
        } elseif ($proposal->deletesOrRenamesFiles) {
            $violation = 'the fix deletes or renames a file';
        } elseif ($proposal->weakensTests) {
            $violation = 'the fix weakens tests';
        } else {
            foreach ($proposal->paths as $path) {
                if ($this->isForbidden($path)) {
                    $violation = 'the fix touches a protected path (`' . $path . '`)';
                    break;
                }
            }
        }

        return $violation;
    }

    /**
     * Whether a path is one a fix must never touch
     *
     * @param string $path Repo-relative path
     *
     * @return bool
     */
    private function isForbidden(string $path): bool
    {
        $normalized = preg_replace('#^(\./)+#', '', str_replace('\\', '/', $path)) ?? '';
        if (str_contains($normalized, '../')) {
            return true; // Not a plain repo-relative path
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
