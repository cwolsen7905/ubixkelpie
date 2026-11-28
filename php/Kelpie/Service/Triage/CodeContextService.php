<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\CodeExcerpt;
use Kelpie\DataTransferObject\Thread;
use Kelpie\GitLab\FileReader;
use Psr\Log\LoggerInterface as Logger;

/**
 * The code a review thread is about, at the MR's head, for triage to judge the finding against
 *
 * The location is the thread's position, or else the first `path:line` its finding names (a
 * finding posted as a general note still names its line). The excerpt is the lines around it,
 * numbered, and capped, so a finding that later commits overtook, or one that needs the code to
 * judge, is not judged from its text alone.
 *
 * @see \Kelpie\Tests\Service\Triage\CodeContextServiceTest PHPUnit test case
 */
final class CodeContextService
{
    /**
     * Lines shown on each side of the line the thread names
     */
    public const int AROUND = 40;

    /**
     * The most characters of code shown
     */
    public const int MAX_CHARS = 12000;

    /**
     * Files commonly named without an extension, taken as a path in a finding's `path:line`
     */
    private const string BARE_FILES = 'Dockerfile|Containerfile|Makefile|Jenkinsfile|Procfile|Gemfile|Vagrantfile|CHANGELOG|LICENSE|README';

    /**
     * Raw file text read during this service's run, by project, sha and path; null is cached too
     *
     * @var array<string, array<string, array<string, ?string>>>
     */
    private array $fileText = [];

    /**
     * Constructor
     *
     * @param Logger     $logger Logger
     * @param FileReader $files  Reads a file at a commit
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly FileReader $files,
    ) {
    }

    /**
     * The excerpt for the thread, empty when it names no readable file and line
     *
     * @param string $project The project ID or path
     * @param string $sha     The MR's head
     * @param Thread $thread  The thread
     *
     * @return CodeExcerpt
     */
    public function excerpt(string $project, string $sha, Thread $thread): CodeExcerpt
    {
        [$path, $line] = $this->location($thread);
        if ($path === '' || $sha === '') {
            return new CodeExcerpt();
        }
        $text = $this->readFile($project, $path, $sha);
        if ($text === null || str_contains($text, "\0")) {
            return new CodeExcerpt();
        }
        $lines = explode("\n", $text);
        $line  = max(1, min($line, count($lines)));
        // The window shrinks around the line until it fits, so the line itself is always shown
        $around = self::AROUND;
        while (true) {
            $from = max(1, $line - $around);
            $to   = min(count($lines), $line + $around);
            $out  = '';
            for ($n = $from; $n <= $to; $n++) {
                $out .= sprintf("%5d  %s\n", $n, $lines[$n - 1]);
            }
            if (strlen($out) <= self::MAX_CHARS || $around === 0) {
                break;
            }
            $around = intdiv($around, 2);
        }
        if (strlen($out) > self::MAX_CHARS) {
            $marker = "\n[code excerpt truncated at " . self::MAX_CHARS . " characters]\n";
            $out    = substr($out, 0, self::MAX_CHARS - strlen($marker)) . $marker;
        }
        $this->logger->debug('Triage code context', ['path' => $path, 'from' => $from, 'to' => $to]);

        return new CodeExcerpt($path, $from, $to, $sha, $out);
    }

    /**
     * The raw file text, fetched at most once per project, sha and path for this service instance
     *
     * @param string $project The project ID or path
     * @param string $path    The file's path in the repository
     * @param string $sha     The commit
     *
     * @return ?string
     */
    private function readFile(string $project, string $path, string $sha): ?string
    {
        if (!array_key_exists($project, $this->fileText)) {
            $this->fileText[$project] = [];
        }
        if (!array_key_exists($sha, $this->fileText[$project])) {
            $this->fileText[$project][$sha] = [];
        }
        if (!array_key_exists($path, $this->fileText[$project][$sha])) {
            $this->fileText[$project][$sha][$path] = $this->files->read($project, $path, $sha);
        }

        return $this->fileText[$project][$sha][$path];
    }

    /**
     * The file and line the thread is about: its position, or the first `path:line` in its text
     *
     * @param Thread $thread The thread
     *
     * @return array{0: string, 1: int} An empty path when there is none
     */
    private function location(Thread $thread): array
    {
        if ($thread->filePath !== null && $this->safePath($thread->filePath)) {
            if ($thread->line === null) {
                return ['', 0];
            }

            return [$thread->filePath, $thread->line];
        }
        // A path names a directory or an extension, or is a common file with none (Dockerfile): a bare word:number such as
        // localhost:8420 is not taken for a file, and cannot shadow the real path:line after it
        $pattern = '#(?<![\w./-])((?:[\w.-]+/)*(?:[\w.-]+\.\w+|' . self::BARE_FILES . ')|(?:[\w.-]+/)+[\w.-]+):(\d+)#';
        if (preg_match($pattern, $thread->body, $m) === 1 && $this->safePath($m[1])) {
            return [$m[1], (int) $m[2]];
        }

        return ['', 0];
    }

    /**
     * Whether a path from a thread is a plain path inside the repository
     *
     * @param string $path The path
     *
     * @return bool
     */
    private function safePath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !in_array('..', explode('/', $path), true) && strlen($path) <= 300;
    }
}
