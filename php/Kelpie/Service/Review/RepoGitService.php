<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewDiff;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Psr\Log\LoggerInterface as Logger;

/**
 * Reads the checkout through git: the reviewable diff, files at the target branch's commit, and whether lines moved
 *
 * @see \Kelpie\Tests\Service\Review\RepoGitServiceTest PHPUnit test case
 */
final class RepoGitService
{
    private const int TIMEOUT = 60;

    /**
     * Paths that are never sent: lockfiles, vendored and generated files
     */
    private const array EXCLUDES = [
        '**/composer.lock', '**/package-lock.json', '**/yarn.lock', '**/pnpm-lock.yaml', '**/Cargo.lock', '**/poetry.lock',
        '**/Gemfile.lock', '**/go.sum', '**/uv.lock', '**/vendor/**', '**/node_modules/**', '**/dist/**',
        '**/*.min.js', '**/*.min.css', '**/*.map', '**/*.pb.go', '**/*.generated.*', '**/*.snap',
    ];

    /**
     * Constructor
     *
     * @param Logger        $logger        Logger
     * @param ProcessRunner $processRunner Runs git
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProcessRunner $processRunner,
    ) {
    }

    /**
     * The diff to review: the given file, or git's diff of the merge request
     *
     * @param ReviewSettings $settings The settings
     *
     * @return ?ReviewDiff Null when git could not diff (the base commit is not fetched)
     */
    public function diff(ReviewSettings $settings): ?ReviewDiff
    {
        if ($settings->diffFile !== '') {
            $text = is_file($settings->diffFile) ? (string) file_get_contents($settings->diffFile) : '';
            preg_match_all('~^diff --git a/.* b/(.*)$~m', $text, $matches);

            return new ReviewDiff($text, array_values(array_unique($matches[1])));
        }

        if ($settings->baseSha === '') {
            return null;
        }

        $pathspec = ['--', '.'];
        foreach (self::EXCLUDES as $glob) {
            $pathspec[] = ':(exclude,glob)' . $glob;
        }

        $names = $this->git($settings, ['diff', '--name-only', $settings->baseSha, $settings->headSha, ...$pathspec]);
        $text  = $this->git($settings, ['diff', '--no-color', '--unified=3', $settings->baseSha, $settings->headSha, ...$pathspec]);
        if ($names === null || $text === null) {
            return null;
        }

        return new ReviewDiff($text, array_values(array_filter(explode("\n", $names))));
    }

    /**
     * A file as it is at a commit
     *
     * @param ReviewSettings $settings The settings
     * @param string         $commit   The commit
     * @param string         $path     The path
     *
     * @return ?string Null when there is no such file
     */
    public function show(ReviewSettings $settings, string $commit, string $path): ?string
    {
        return $this->git($settings, ['show', $commit . ':' . $path]);
    }

    /**
     * The files of a commit's tree
     *
     * @param ReviewSettings $settings The settings
     * @param string         $commit   The commit
     *
     * @return list<string>
     */
    public function tree(ReviewSettings $settings, string $commit): array
    {
        return array_values(array_filter(explode("\n", $this->git($settings, ['ls-tree', '-r', '--name-only', $commit]) ?? '')));
    }

    /**
     * Whether lines near one changed between the commit a thread was posted on and the commit under review
     *
     * Unknown (no sha, a sha not fetched, no such file) answers no: a thread is only called possibly fixed on
     * evidence that its code moved.
     *
     * @param ReviewSettings $settings The settings
     * @param string         $file     The file the thread points at
     * @param int            $line     The line it points at
     * @param string         $sha      The commit it was posted on
     *
     * @return bool
     */
    public function changedNear(ReviewSettings $settings, string $file, int $line, string $sha): bool
    {
        if ($line <= 0 || $sha === '' || $sha === $settings->headSha || $this->git($settings, ['cat-file', '-e', $sha . '^{commit}']) === null) {
            return false;
        }

        $hunks = $this->git($settings, ['diff', '-U0', '--no-color', $sha, $settings->headSha, '--', $file]) ?? '';
        if (preg_match_all('~^@@ -\S+ \+(\d+)(?:,(\d+))? @@~m', $hunks, $matches, PREG_SET_ORDER) < 1) {
            return false;
        }

        foreach ($matches as $match) {
            $start = (int) $match[1];
            $count = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 1;
            $end   = $start + max($count, 1) - 1;
            if ($start <= $line + 3 && $end >= $line - 3) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run git in the checkout
     *
     * @param ReviewSettings $settings The settings
     * @param string[]       $args     The arguments after `git`
     *
     * @return ?string Standard output, null on any failure
     */
    private function git(ReviewSettings $settings, array $args): ?string
    {
        $env    = ['PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'), 'HOME' => (string) (getenv('HOME') ?: '/tmp'), 'GIT_TERMINAL_PROMPT' => '0'];
        $result = $this->processRunner->run(['git', '-c', 'safe.directory=*', '-C', $settings->repo, ...$args], $settings->repo, $env, self::TIMEOUT);

        if ($result->exitCode !== 0) {
            $this->logger->debug('git failed', ['args' => $args, 'exit' => $result->exitCode]);

            return null;
        }

        return $result->stdout;
    }
}
