<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewSettings;
use Psr\Log\LoggerInterface as Logger;

/**
 * Reads the repository's standards documents for the model, from the target branch's commit
 *
 * Reading the base commit and not the merge request's head means a merge request cannot rewrite the rules it is
 * judged by. Without a base commit (a local run) the working tree is read.
 *
 * @see \Kelpie\Tests\Service\Review\StandardsServiceTest PHPUnit test case
 */
final class StandardsService
{
    private const string PATTERN   = '~^(CLAUDE\.md|AGENTS\.md|CONTRIBUTING\.md|\.ai-review\.md|docs/standards/.+\.md)$~';
    private const int FILE_BYTES   = 20480;
    private const int BUDGET_BYTES = 61440;

    /**
     * Constructor
     *
     * @param Logger         $logger  Logger
     * @param RepoGitService $repoGit Reads files at a commit
     */
    public function __construct(
        private readonly Logger $logger, // @phpstan-ignore property.onlyWritten (Every uBix class takes a logger first; reading has nothing to log)
        private readonly RepoGitService $repoGit,
    ) {
    }

    /**
     * The standards as Markdown sections, sorted by path, within a size budget
     *
     * @param ReviewSettings $settings The settings
     *
     * @return string Empty when the repository has none
     */
    public function read(ReviewSettings $settings): string
    {
        $fromGit = $settings->baseSha !== '' && $settings->diffFile === '';
        $paths   = $fromGit ? $this->repoGit->tree($settings, $settings->baseSha) : $this->workingTreePaths($settings->repo);
        $wanted  = [];
        foreach ($paths as $path) {
            if (preg_match(self::PATTERN, $path) === 1) {
                $wanted[] = $path;
            }
        }
        sort($wanted);
        $paths = $wanted;

        $budget = self::BUDGET_BYTES;
        $out    = '';
        foreach ($paths as $path) {
            $body = $fromGit ? $this->repoGit->show($settings, $settings->baseSha, $path) : file_get_contents($settings->repo . '/' . $path);
            $body = substr((string) $body, 0, self::FILE_BYTES);
            if ($body === '') {
                continue;
            }
            if ($budget <= 0) {
                break;
            }
            $budget -= strlen($body);
            $out    .= sprintf("## %s\n\n%s\n\n", $path, $body);
        }

        return $out;
    }

    /**
     * The repository's markdown files outside .git, vendor and node_modules, relative to its root
     *
     * @param string $repo The checkout
     *
     * @return list<string>
     */
    private function workingTreePaths(string $repo): array
    {
        $paths = [];
        foreach (['CLAUDE.md', 'AGENTS.md', 'CONTRIBUTING.md', '.ai-review.md'] as $name) {
            if (is_file($repo . '/' . $name)) {
                $paths[] = $name;
            }
        }
        foreach (glob($repo . '/docs/standards/{,*/,*/*/}*.md', GLOB_BRACE) ?: [] as $path) {
            $paths[] = substr($path, strlen($repo) + 1);
        }

        return $paths;
    }
}
