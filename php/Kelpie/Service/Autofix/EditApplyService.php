<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\ProposedEdit;
use Psr\Log\LoggerInterface as Logger;

/**
 * Applies a model's exact-text edits to a scratch checkout, or refuses all of them
 *
 * An edit stands only if its file is a plain file inside the checkout (not under `.git`, no `..`, no symlink out),
 * its `old` text is not empty and occurs exactly once, and it changes something. An edit with an empty `old` creates
 * a file instead: only one that does not exist yet, inside the checkout and outside `.git` (missing folders are made),
 * with some text. Edits apply in order, each
 * to the text the one before left. If any edit is refused nothing is written, so a proposal either applies in full or
 * does not exist.
 *
 * @see \Kelpie\Tests\Service\Autofix\EditApplyServiceTest PHPUnit test case
 */
final class EditApplyService
{
    public const int MAX_EDITS = 5;

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
     * Apply the edits
     *
     * @param string         $directory The checkout
     * @param ProposedEdit[] $edits     The edits
     *
     * @return ?string Why they were refused, null when all applied
     */
    public function apply(string $directory, array $edits): ?string
    {
        $root = realpath($directory);
        if ($root === false || $edits === []) {
            return 'There is nothing to apply.';
        }
        if (count($edits) > self::MAX_EDITS) {
            return 'More than ' . self::MAX_EDITS . ' edits.';
        }

        $texts = [];
        foreach ($edits as $number => $edit) {
            if ($edit->old === '' && $edit->new !== '' && $this->resolve($root, $edit->file) === null) {
                $path = $this->newFile($root, $edit->file);
                if ($path === null || isset($texts[$path])) {
                    return sprintf('Edit %d creates a file that cannot be made in the repository (`%s`).', $number + 1, $edit->file);
                }

                $texts[$path] = $edit->new;

                continue;
            }

            // A file an earlier edit of this list creates is not on disk yet
            $path    = $this->resolve($root, $edit->file);
            $created = $path === null ? $this->newFile($root, $edit->file) : null;
            $path  ??= $created !== null && isset($texts[$created]) ? $created : null;
            if ($path === null) {
                return sprintf('Edit %d names a file that is not a plain file in the repository (`%s`).', $number + 1, $edit->file);
            }

            $texts[$path] ??= (string) file_get_contents($path);
            if ($edit->old === '' || $edit->old === $edit->new) {
                return sprintf('Edit %d changes nothing.', $number + 1);
            }

            $count = substr_count($texts[$path], $edit->old);
            if ($count !== 1) {
                return sprintf('Edit %d: the old text occurs %d times in `%s`, not once.', $number + 1, $count, $edit->file);
            }

            $texts[$path] = str_replace($edit->old, $edit->new, $texts[$path]);
        }

        foreach ($texts as $path => $text) {
            if ((!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) || file_put_contents($path, $text) === false) {
                $this->logger->error('Propose: could not write an edited file', ['path' => basename($path)]);

                return 'An edited file could not be written.';
            }
        }

        return null;
    }

    /**
     * Where a new file would go: nothing there yet, not even a link, inside the checkout and outside `.git`
     *
     * Its folder may not exist yet: the deepest folder of the path that does exist must be a real folder inside the
     * checkout, and the rest is made when the edits are written (an agent may add a test in a new folder).
     *
     * @param string $root The checkout's real path
     * @param string $file The repo-relative path the model gave
     *
     * @return ?string
     */
    private function newFile(string $root, string $file): ?string
    {
        if (!$this->plainRelative($file)) {
            return null;
        }

        $parts   = explode('/', trim($file, '/'));
        $name    = array_pop($parts);
        $missing = [];
        while ($parts !== [] && !file_exists($root . '/' . implode('/', $parts)) && !is_link($root . '/' . implode('/', $parts))) {
            array_unshift($missing, array_pop($parts));
        }

        $folder = realpath($root . ($parts === [] ? '' : '/' . implode('/', $parts)));
        if ($folder === false || !is_dir($folder) || ($folder !== $root && !str_starts_with($folder, $root . '/'))) {
            return null;
        }

        $relative = ltrim(substr($folder, strlen($root)) . '/' . implode('/', $missing), '/');
        if ($relative === '.git' || str_starts_with($relative, '.git/') || in_array('', $missing, true) || in_array('.', $missing, true)) {
            return null;
        }

        $path = rtrim($folder . '/' . implode('/', $missing), '/') . '/' . $name;

        return file_exists($path) || is_link($path) ? null : $path;
    }

    /**
     * Whether a repo-relative path the model gave is plain: not empty, not absolute, no `..`, no NUL or backslash
     *
     * @param string $file The path
     *
     * @return bool
     */
    private function plainRelative(string $file): bool
    {
        return $file !== '' && !str_contains($file, "\0") && !str_starts_with($file, '/') && !str_contains($file, '\\') && !in_array('..', explode('/', $file), true);
    }

    /**
     * The real path of a repo-relative file, only if it is an existing plain file inside the checkout
     *
     * @param string $root The checkout's real path
     * @param string $file The repo-relative path the model gave
     *
     * @return ?string
     */
    private function resolve(string $root, string $file): ?string
    {
        if (!$this->plainRelative($file)) {
            return null;
        }

        $path = realpath($root . '/' . $file);
        if ($path === false || !is_file($path) || !str_starts_with($path, $root . '/')) {
            return null;
        }

        $relative = substr($path, strlen($root) + 1);

        return $relative === '.git' || str_starts_with($relative, '.git/') ? null : $path;
    }
}
