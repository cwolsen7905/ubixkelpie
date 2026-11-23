<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

use Psr\Log\LoggerInterface as Logger;

/**
 * The files a fix to one review thread may edit or create, and nothing else
 *
 * The set is: the file the thread is on, files the thread's text names that exist in the checkout, files the merge
 * request changed, and the companion tests of all of those. A companion test is named by convention: in this repo
 * `php/Kelpie/<X>.php` and `tests/<X>Test.php` pair up; elsewhere a test sits beside its file (`x_test.ext`,
 * `x.test.ext`, `x.spec.ext`, `xTest.ext`) or in a `tests/` folder that mirrors the source. A companion need not exist
 * yet: a new test may be made at its canonical path. The scope is the whole boundary; protected paths are checked
 * separately and win over it.
 *
 * @see \Kelpie\Tests\Autofix\EditScopeTest PHPUnit test case
 */
final readonly class EditScope
{
    public const int MAX_NAMED = 20;

    /**
     * Constructor
     *
     * @param Logger              $logger  Logger
     * @param array<string, true> $allowed Repo-relative paths that may be edited or created, as keys
     * @param string[]            $shown   The allowed paths that exist in the checkout, the thread's file first
     */
    public function __construct(
        private Logger $logger,
        private array $allowed = [],
        private array $shown = [],
    ) {
    }

    /**
     * Work out the scope for a thread, as a new scope
     *
     * @param string   $directory  The checkout
     * @param ?string  $threadFile The file the thread is on
     * @param string   $text       The thread's text and steering, searched for file names
     * @param string[] $changed    Files the merge request changed
     *
     * @return self
     */
    public function forThread(string $directory, ?string $threadFile, string $text, array $changed = []): self
    {
        $root = realpath($directory);
        $base = [];
        if ($threadFile !== null && $this->clean($threadFile) !== '') {
            $base[] = $this->clean($threadFile);
        }

        $named = [];
        if ($root !== false && preg_match_all('#[A-Za-z0-9_.@-]+(?:/[A-Za-z0-9_.@-]+)*\.[A-Za-z0-9]{1,8}#', $text, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                $candidate = $this->clean($candidate);
                if ($candidate !== '' && !in_array($candidate, $base, true) && !in_array($candidate, $named, true) && $this->isFile($root, $candidate)) {
                    $named[] = $candidate;
                }
                if (count($named) >= self::MAX_NAMED) {
                    break;
                }
            }
        }

        $sources = [...$base, ...$named];
        foreach ($changed as $path) {
            if ($this->clean($path) !== '') {
                $sources[] = $this->clean($path);
            }
        }
        $sources = array_values(array_unique($sources));

        $allowed = [];
        foreach ($sources as $path) {
            $allowed[$path] = true;
            foreach ($this->companions($path, $root === false ? null : $root) as $companion) {
                $allowed[$companion] = true;
            }
        }

        $shown = [];
        foreach (array_keys($allowed) as $path) {
            if ($root !== false && $this->isFile($root, (string) $path)) {
                $shown[] = (string) $path;
            }
        }

        usort($shown, static function (string $left, string $right) use ($base): int {
            return [!in_array($left, $base, true), $left] <=> [!in_array($right, $base, true), $right];
        });

        return new self($this->logger, $allowed, $shown);
    }

    /**
     * Whether a path may be edited or created
     *
     * @param string $path Repo-relative path
     *
     * @return bool
     */
    public function allows(string $path): bool
    {
        $allowed = isset($this->allowed[$this->clean($path)]);
        if (!$allowed) {
            $this->logger->debug('Autofix edit scope refused a path', ['path' => $path]);
        }

        return $allowed;
    }

    /**
     * The allowed paths that exist in the checkout, the thread's own file first
     *
     * @return string[]
     */
    public function existing(): array
    {
        return $this->shown;
    }

    /**
     * The allowed paths that do not exist yet: the places a new file (a companion test) may go
     *
     * @return string[]
     */
    public function creatable(): array
    {
        $new = array_values(array_diff(array_map('strval', array_keys($this->allowed)), $this->shown));
        sort($new);

        return $new;
    }

    /**
     * The companion test paths of a file, or the file a test belongs to; none for a file that is neither
     *
     * In this repo `php/Kelpie/<X>.php` pairs with `tests/<X>Test.php`, and that is all it pairs with. Elsewhere the
     * conventional siblings count when they exist in the checkout, and one canonical name for the file's language
     * may be made new.
     *
     * @param string  $path Repo-relative path
     * @param ?string $root The checkout's real path, to find siblings that exist; null for the canonical name only
     *
     * @return string[]
     */
    public function companions(string $path, ?string $root = null): array
    {
        $path = $this->clean($path);
        if ($path === '') {
            return [];
        }

        if (str_starts_with($path, 'php/Kelpie/') && str_ends_with($path, '.php')) {
            return ['tests/' . substr($path, strlen('php/Kelpie/'), -4) . 'Test.php'];
        }
        if (str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php')) {
            return ['php/Kelpie/' . substr($path, strlen('tests/'), -8) . '.php'];
        }

        $dir  = dirname($path) === '.' ? '' : dirname($path) . '/';
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext === '' || $stem === '') {
            return [];
        }

        // A test names the file it is for
        if (preg_match('/^(.+?)(?:_test|\.test|\.spec|Test)$/', $stem, $m) === 1) {
            return [$dir . $m[1] . '.' . $ext];
        }
        if (str_starts_with($stem, 'test_') && strlen($stem) > 5) {
            return [$dir . substr($stem, 5) . '.' . $ext];
        }

        $canonical = match ($ext) {
            'go', 'rs' => $dir . $stem . '_test.' . $ext,
            'js', 'jsx', 'ts', 'tsx', 'mjs' => $dir . $stem . '.test.' . $ext,
            'py' => $dir . 'test_' . $stem . '.py',
            default => $dir . $stem . 'Test.' . $ext,
        };

        $out = [$canonical];
        if ($root !== null) {
            foreach (['_test.' . $ext, '.test.' . $ext, '.spec.' . $ext, 'Test.' . $ext] as $suffix) {
                if ($this->isFile($root, $dir . $stem . $suffix)) {
                    $out[] = $dir . $stem . $suffix;
                }
            }
            foreach (['tests/', 'test/'] as $folder) {
                foreach ([$stem . 'Test.' . $ext, 'test_' . $stem . '.py', $stem . '.test.' . $ext] as $name) {
                    if ($this->isFile($root, $folder . $dir . $name)) {
                        $out[] = $folder . $dir . $name;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A path with a leading `./` and doubled slashes removed; empty when it is not a plain repo-relative path
     *
     * @param string $path The path
     *
     * @return string
     */
    private function clean(string $path): string
    {
        $path = (string) preg_replace('#^(\./)+#', '', str_replace('\\', '/', trim($path)));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '//') || in_array('..', explode('/', $path), true)) {
            return '';
        }

        return $path === '.git' || str_starts_with($path, '.git/') ? '' : $path;
    }

    /**
     * Whether a path is a plain file inside the checkout
     *
     * @param string $root The checkout's real path
     * @param string $path The repo-relative path
     *
     * @return bool
     */
    private function isFile(string $root, string $path): bool
    {
        $real = realpath($root . '/' . $path);

        return $real !== false && is_file($real) && str_starts_with($real, $root . '/');
    }
}
