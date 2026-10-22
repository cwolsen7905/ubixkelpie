<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\ChangeSet;
use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * A throwaway git checkout of an MR source branch at one sha, and the git steps the fixer needs
 *
 * The GitLab token reaches git only through a GIT_ASKPASS script that exists for the length of one
 * network call (mode 0700, outside the checkout) and is never in an environment. Push is always
 * `HEAD:refs/heads/<branch>`: no force flag, no `+` refspec, so a branch that moved is rejected by
 * the server. Every git call gets an exact, minimal environment.
 *
 * @see \Kelpie\Tests\Sandbox\CheckoutTest PHPUnit test case
 */
final class Checkout
{
    private const int GIT_TIMEOUT = 300;

    private string $root = '';

    /**
     * Constructor
     *
     * @param Logger        $logger Logger
     * @param ProcessRunner $runner The process runner
     * @param string        $token  The bot's GitLab token, for clone and push only
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $runner,
        private readonly string $token,
    ) {
    }

    /**
     * The checkout directory (empty before open())
     *
     * @return string
     */
    public function directory(): string
    {
        return $this->root === '' ? '' : $this->root . '/repo';
    }

    /**
     * Clone a branch and detach at a sha
     *
     * @param string $url    The remote URL
     * @param string $branch The source branch
     * @param string $sha    The sha to check out; must be the branch head's ancestor-or-self
     *
     * @return bool Whether HEAD is now at the sha
     */
    public function open(string $url, string $branch, string $sha): bool
    {
        if (!$this->isSafeRef($branch) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return false;
        }

        $root = sys_get_temp_dir() . '/kelpie-fix-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0700) || !mkdir($root . '/repo', 0700)) {
            return false;
        }
        $this->root = $root;

        if (!$this->networkGit(['clone', '--no-tags', '--branch', $branch, '--', $url, $root . '/repo'], $root)) {
            return false;
        }

        return $this->git(['checkout', '--detach', $sha])->exitCode === 0 && trim($this->git(['rev-parse', 'HEAD'])->stdout) === $sha;
    }

    /**
     * Stage everything the agent did and measure it
     *
     * @return ?ChangeSet Null when git could not say
     */
    public function stageAndMeasure(): ?ChangeSet
    {
        if ($this->git(['add', '-A'])->exitCode !== 0) {
            return null;
        }

        $numstat = $this->git(['diff', '--cached', '--numstat', '-M', '-z']);
        $status  = $this->git(['diff', '--cached', '--name-status', '-M', '-z']);
        $added   = $this->git(['diff', '--cached', '-U0', '--no-color']);
        if ($numstat->exitCode !== 0 || $status->exitCode !== 0 || $added->exitCode !== 0) {
            return null;
        }

        $lines = 0;
        foreach (array_filter(explode("\0", $numstat->stdout)) as $entry) {
            $parts  = explode("\t", $entry);
            $lines += is_numeric($parts[0]) ? (int) $parts[0] : 0;
            $lines += is_numeric($parts[1] ?? null) ? (int) $parts[1] : 0;
        }

        // The name-status -z format is STATUS NUL path [NUL newpath]
        $tokens = explode("\0", rtrim($status->stdout, "\0"));
        $paths  = [];
        $moves  = false;
        $weaken = false;
        while (($code = array_shift($tokens)) !== null) {
            if ($code === '') {
                continue;
            }
            $kind    = $code[0];
            $path    = (string) array_shift($tokens);
            $paths[] = $path;
            if ($kind === 'R' || $kind === 'C') {
                $moves   = true;
                $paths[] = (string) array_shift($tokens);
            }
            if ($kind === 'D') {
                $moves  = true;
                $weaken = $weaken || str_starts_with($path, 'tests/');
            }
        }

        $weaken = $weaken || preg_match('/^\+.*(markTestSkipped|markTestIncomplete|#\[Skip|@group\s+skip)/mi', $added->stdout) === 1;

        return new ChangeSet($lines, array_values(array_unique($paths)), $moves, $weaken);
    }

    /**
     * The staged changes as the diff git writes, which `git apply` takes at the sha the checkout is at
     *
     * @return ?string Null when git could not say
     */
    public function stagedDiff(): ?string
    {
        $result = $this->git(['diff', '--cached', '--no-color', '--no-ext-diff', '-M']);

        return $result->exitCode === 0 ? $result->stdout : null;
    }

    /**
     * Commit the staged changes
     *
     * @param string $message The commit message
     *
     * @return ?string The new commit sha, null on failure
     */
    public function commit(string $message): ?string
    {
        $result = $this->git(['-c', 'user.name=kelpie-bot', '-c', 'user.email=kelpie-bot@ubixsys.com', '-c', 'commit.gpgsign=false', 'commit', '--no-verify', '-m', $message]);
        if ($result->exitCode !== 0) {
            return null;
        }

        $sha = trim($this->git(['rev-parse', 'HEAD'])->stdout);

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /**
     * Push HEAD to the source branch, never forced
     *
     * @param string $branch The MR source branch
     *
     * @return bool Whether the server accepted it (a non-fast-forward is rejected)
     */
    public function push(string $branch): bool
    {
        if (!$this->isSafeRef($branch)) {
            return false;
        }

        return $this->networkGit(['push', '--no-verify', 'origin', 'HEAD:refs/heads/' . $branch], $this->directory());
    }

    /**
     * Remove the checkout
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->root !== '' && str_starts_with(basename($this->root), 'kelpie-fix-')) {
            $this->runner->run(['rm', '-rf', '--', $this->root], sys_get_temp_dir(), $this->baseEnv(), 60);
        }
        $this->root = '';
    }

    /**
     * A branch name that is a plain ref: no leading dash or plus, no spaces, no `..`
     *
     * @param string $branch The branch
     *
     * @return bool
     */
    private function isSafeRef(string $branch): bool
    {
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch) === 1 && !str_contains($branch, '..') && !str_ends_with($branch, '/') && !str_ends_with($branch, '.lock');
    }

    /**
     * Run a local git command in the checkout
     *
     * @param string[] $args The arguments after `git`
     *
     * @return ProcessResult
     */
    private function git(array $args): ProcessResult
    {
        return $this->runner->run(['git', ...$args], $this->directory(), $this->baseEnv(), self::GIT_TIMEOUT);
    }

    /**
     * Run a git command that talks to the remote, with the token available through GIT_ASKPASS only for its duration
     *
     * @param string[] $args The arguments after `git`
     * @param string   $cwd  Where to run
     *
     * @return bool Whether it succeeded
     */
    private function networkGit(array $args, string $cwd): bool
    {
        $script = $this->root . '/askpass.sh';
        $body   = "#!/bin/sh\ncase \"\$1\" in\n  Username*) echo oauth2 ;;\n  *) echo " . escapeshellarg($this->token) . " ;;\nesac\n";

        if (file_put_contents($script, $body) === false || !chmod($script, 0700)) {
            return false;
        }

        try {
            $result = $this->runner->run(['git', ...$args], $cwd, $this->baseEnv() + ['GIT_ASKPASS' => $script, 'GIT_TERMINAL_PROMPT' => '0'], self::GIT_TIMEOUT);
        } finally {
            unlink($script);
        }

        if ($result->exitCode !== 0) {
            // Scrub the token if it somehow appears in git's output.
            $this->logger->warning('Autofix git call failed', ['command' => $args[0], 'error' => str_replace($this->token, '***', substr($result->stderr, 0, 500))]);
        }

        return $result->exitCode === 0;
    }

    /**
     * The minimal environment every git call gets
     *
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'HOME'                => $this->root !== '' ? $this->root : sys_get_temp_dir(),
            'LC_ALL'              => 'C',
            'PATH'                => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];
    }
}
