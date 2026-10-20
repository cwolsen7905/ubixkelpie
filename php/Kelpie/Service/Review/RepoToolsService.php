<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Psr\Log\LoggerInterface as Logger;

/**
 * The model's three read-only tools over the checkout: read, grep and glob
 *
 * Nothing else: no shell, no write, no network. A path is resolved and must stay inside the checkout (a
 * symlink out of it is refused), and `.git` is never opened. Results are capped so one call cannot flood the
 * context. Errors come back as the tool's text, never as an exception, so the model can correct itself.
 *
 * @see \Kelpie\Tests\Service\Review\RepoToolsServiceTest PHPUnit test case
 */
final class RepoToolsService
{
    private const array SKIPPED_DIRS = ['.git', 'vendor', 'node_modules'];
    private const int MAX_READ_LINES = 400;
    private const int MAX_READ_BYTES = 40960;
    private const int MAX_MATCHES    = 100;
    private const int MAX_PATHS      = 200;
    private const int MAX_FILE_BYTES = 524288;

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
     * The tool definitions in the shape chat completions take
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $string = ['type' => 'string'];

        return [
            $this->definition('read', 'Read a file of the repository, numbered by line. Long files are cut; pass offset and limit to page.', [
                'limit'  => ['type' => 'integer', 'description' => 'Lines to read (max ' . self::MAX_READ_LINES . ')'],
                'offset' => ['type' => 'integer', 'description' => 'First line, 1-based'],
                'path'   => $string,
            ], ['path']),
            $this->definition('grep', 'Search file contents with a regular expression (PCRE, no delimiters). To look for many symbols use one pattern with an alternation.', [
                'include' => ['type' => 'string', 'description' => 'Only files whose path matches this glob, e.g. *.php'],
                'path'    => ['type' => 'string', 'description' => 'A file or directory to search in, default the whole repository'],
                'pattern' => $string,
            ], ['pattern']),
            $this->definition('glob', 'List files whose path matches a glob (** crosses directories).', ['pattern' => $string], ['pattern']),
        ];
    }

    /**
     * Run one tool call
     *
     * @param string       $repo The checkout
     * @param string       $name The tool
     * @param array<mixed> $args The decoded arguments
     *
     * @return string The tool's text
     */
    public function run(string $repo, string $name, array $args): string
    {
        $root = realpath($repo);
        if ($root === false) {
            return 'error: the repository is not readable';
        }

        $this->logger->debug('Review tool call', ['tool' => $name]);

        return match ($name) {
            'read'  => $this->read($root, $this->str($args, 'path'), $this->int($args, 'offset', 1), $this->int($args, 'limit', self::MAX_READ_LINES)),
            'grep'  => $this->grep($root, $this->str($args, 'pattern'), $this->str($args, 'path'), $this->str($args, 'include')),
            'glob'  => $this->glob($root, $this->str($args, 'pattern')),
            default => 'error: unknown tool ' . $name . ' (the tools are read, grep and glob)',
        };
    }

    /**
     * @param string               $name        The tool
     * @param string               $description What it does
     * @param array<string, mixed> $properties  The parameters
     * @param string[]             $required    The required parameters
     *
     * @return array<string, mixed>
     */
    private function definition(string $name, string $description, array $properties, array $required): array
    {
        return [
            'function' => [
                'description' => $description,
                'name'        => $name,
                'parameters'  => ['type' => 'object', 'properties' => $properties, 'required' => $required],
            ],
            'type'     => 'function',
        ];
    }

    /**
     * @param array<mixed> $args The arguments
     * @param string       $key  The argument
     *
     * @return string
     */
    private function str(array $args, string $key): string
    {
        return is_string($args[$key] ?? null) ? $args[$key] : '';
    }

    /**
     * @param array<mixed> $args    The arguments
     * @param string       $key     The argument
     * @param int          $default Used when it is missing or not a number
     *
     * @return int
     */
    private function int(array $args, string $key, int $default): int
    {
        $value = $args[$key] ?? null;

        return is_int($value) || is_float($value) ? (int) $value : $default;
    }

    /**
     * Resolve a path inside the checkout
     *
     * @param string $root The checkout's real path
     * @param string $path The path as the model wrote it
     *
     * @return ?string The real path, null when it is outside the checkout, inside .git or missing
     */
    private function inside(string $root, string $path): ?string
    {
        $real = realpath($root . '/' . ltrim($path, '/'));
        if ($real === false || ($real !== $root && !str_starts_with($real, $root . '/'))) {
            return null;
        }
        $relative = ltrim(substr($real, strlen($root)), '/');

        return in_array('.git', explode('/', $relative), true) ? null : $real;
    }

    /**
     * @param string $root   The checkout's real path
     * @param string $path   The file
     * @param int    $offset First line, 1-based
     * @param int    $limit  Lines wanted
     *
     * @return string
     */
    private function read(string $root, string $path, int $offset, int $limit): string
    {
        $real = $this->inside($root, $path);
        if ($real === null || !is_file($real)) {
            return 'error: no such file in the repository: ' . $path;
        }

        $lines  = preg_split('~\R~', (string) file_get_contents($real, false, null, 0, self::MAX_FILE_BYTES)) ?: [];
        $offset = max($offset, 1);
        $limit  = min(max($limit, 1), self::MAX_READ_LINES);
        $out    = '';
        foreach (array_slice($lines, $offset - 1, $limit, true) as $i => $line) {
            $out .= sprintf("%d: %s\n", $i + 1, $line);
            if (strlen($out) > self::MAX_READ_BYTES) {
                return $out . "[cut: pass a larger offset to continue]\n";
            }
        }

        return $out === '' ? '(the file has no lines at that offset)' : $out;
    }

    /**
     * @param string $root    The checkout's real path
     * @param string $pattern The regular expression
     * @param string $path    The file or directory to search
     * @param string $include A glob the file path must match
     *
     * @return string
     */
    private function grep(string $root, string $pattern, string $path, string $include): string
    {
        $regex = '~' . str_replace('~', '\~', $pattern) . '~u';
        if ($pattern === '' || !$this->validRegex($regex)) {
            return 'error: the pattern is not a valid regular expression';
        }

        $start = $path === '' ? $root : $this->inside($root, $path);
        if ($start === null) {
            return 'error: no such path in the repository: ' . $path;
        }
        $includeRegex = $include === '' ? null : $this->globRegex($include);

        $out   = '';
        $found = 0;
        foreach ($this->files($root, $start) as $relative => $file) {
            if (($includeRegex !== null && preg_match($includeRegex, $relative) !== 1 && preg_match($includeRegex, basename($relative)) !== 1) || filesize($file) > self::MAX_FILE_BYTES) {
                continue;
            }
            $content = (string) file_get_contents($file);
            if (str_contains($content, "\0")) {
                continue;
            }
            foreach (preg_split('~\R~', $content) ?: [] as $i => $line) {
                if (preg_match($regex, $line) === 1) {
                    $out .= sprintf("%s:%d: %s\n", $relative, $i + 1, mb_substr($line, 0, 300));
                    $found++;
                    if ($found >= self::MAX_MATCHES) {
                        return $out . '[cut at ' . self::MAX_MATCHES . " matches: narrow the pattern or the path]\n";
                    }
                }
            }
        }

        return $out === '' ? '(no matches)' : $out;
    }

    /**
     * @param string $root    The checkout's real path
     * @param string $pattern The glob
     *
     * @return string
     */
    private function glob(string $root, string $pattern): string
    {
        if ($pattern === '') {
            return 'error: the pattern is empty';
        }
        $regex = $this->globRegex($pattern);
        $out   = '';
        $found = 0;
        foreach (array_keys($this->files($root, $root)) as $relative) {
            if (preg_match($regex, $relative) === 1) {
                $out .= $relative . "\n";
                $found++;
                if ($found >= self::MAX_PATHS) {
                    return $out . '[cut at ' . self::MAX_PATHS . " paths: narrow the pattern]\n";
                }
            }
        }

        return $out === '' ? '(no files match)' : $out;
    }

    /**
     * The regular files under a path, skipping .git, vendor, node_modules and symlinks, as relative path => real path
     *
     * @param string $root  The checkout's real path
     * @param string $start The file or directory to walk
     *
     * @return array<string, string>
     */
    private function files(string $root, string $start): array
    {
        if (is_file($start)) {
            return [ltrim(substr($start, strlen($root)), '/') => $start];
        }

        $paths = [];
        $names = is_readable($start) ? scandir($start) : false;
        $names = $names === false ? [] : $names;
        sort($names);
        foreach ($names as $name) {
            $full = $start . '/' . $name;
            if ($name === '.' || $name === '..' || is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                if (!in_array($name, self::SKIPPED_DIRS, true)) {
                    $paths = [...$paths, ...$this->files($root, $full)];
                }
            } elseif (is_file($full)) {
                $paths[ltrim(substr($full, strlen($root)), '/')] = $full;
            }
        }

        return $paths;
    }

    /**
     * Whether a delimited pattern compiles
     *
     * @param string $regex The pattern with delimiters
     *
     * @return bool
     */
    private function validRegex(string $regex): bool
    {
        set_error_handler(static function (): bool {
            return true;
        });
        $valid = preg_match($regex, '') !== false;
        restore_error_handler();

        return $valid;
    }

    /**
     * A glob as a regular expression: `**` crosses directories, `*` and `?` do not
     *
     * @param string $glob The glob
     *
     * @return string
     */
    private function globRegex(string $glob): string
    {
        $regex = '';
        for ($i = 0, $n = strlen($glob); $i < $n; $i++) {
            $c = $glob[$i];
            if ($c === '*' && ($glob[$i + 1] ?? '') === '*') {
                $slash  = ($glob[$i + 2] ?? '') === '/';
                $regex .= $slash ? '(?:.*/)?' : '.*';
                $i     += $slash ? 2 : 1;
            } elseif ($c === '*') {
                $regex .= '[^/]*';
            } elseif ($c === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($c, '~');
            }
        }

        return '~^' . $regex . '$~';
    }
}
