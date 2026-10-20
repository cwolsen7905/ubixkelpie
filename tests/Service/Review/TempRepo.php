<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A throwaway directory of files for the tests that read a checkout
 */
final class TempRepo
{
    private string $path;

    /**
     * Constructor
     *
     * @param array<string, string> $files Path (relative to the root) => content
     */
    public function __construct(
        array $files,
    ) {
        $this->path = sys_get_temp_dir() . '/kelpie-review-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0o777, true);
        foreach ($files as $relative => $content) {
            $full = $this->path . '/' . $relative;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            file_put_contents($full, $content);
        }
    }

    /**
     * The root
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Add a symlink inside the root
     *
     * @param string $name   The link's path relative to the root
     * @param string $target What it points at
     *
     * @return void
     */
    public function link(string $name, string $target): void
    {
        symlink($target, $this->path . '/' . $name);
    }

    /**
     * Delete the directory
     *
     * @return void
     */
    public function remove(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->path);
    }
}
