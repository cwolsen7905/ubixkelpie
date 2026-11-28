<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\GateServer;
use Kelpie\Sandbox\ProcessReaperInterface as ProcessReaper;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Sandbox\TarArchive;
use Kelpie\Sandbox\WritableMountCheck;
use Kelpie\Security\ActionGuard;
use Psr\Log\NullLogger;
use Ubix\Service\JsonService;

/**
 * Checkouts, archives and a server for the gate tests: temporary directories, and tar entries built by hand
 */
final class GateFixtures
{
    /**
     * A fresh, empty temporary directory
     *
     * @return string
     */
    public static function directory(): string
    {
        $directory = sys_get_temp_dir() . '/kelpie-gate-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);

        return (string) realpath($directory);
    }

    /**
     * A checkout whose vendor/bin holds the given shell scripts
     *
     * @param array<string, string> $scripts Program name (phpcs, phpstan, phpunit) => script body after the #!/bin/sh line
     *
     * @return string The checkout (`<tmp>/repo`, so HOME is its parent as in a real run)
     */
    public static function checkout(array $scripts): string
    {
        $checkout = self::directory() . '/repo';
        mkdir($checkout . '/vendor/bin', 0700, true);
        foreach ($scripts as $name => $body) {
            file_put_contents($checkout . '/vendor/bin/' . $name, "#!/bin/sh\n" . $body . "\n");
            chmod($checkout . '/vendor/bin/' . $name, 0755);
        }

        return $checkout;
    }

    /**
     * A real gate server with the plain process runner
     *
     * @param ?ProcessReaper $reaper Clears the container between runs; null is one that finds nothing to clear
     *
     * @return GateServer
     */
    public static function server(?ProcessReaper $reaper = null): GateServer
    {
        $logger = new NullLogger();

        return new GateServer($logger, new ActionGuard($logger), new ProcOpenProcessRunner($logger), new TarArchive($logger), new JsonService($logger), $reaper ?? new ScriptedReaper(), new WritableMountCheck($logger, '/nonexistent', 4242));
    }

    /**
     * Remove a directory tree (links are removed, not followed)
     *
     * @param string $path The path
     *
     * @return void
     */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0700);
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }

    /**
     * One raw ustar entry: header and padded content
     *
     * @param string $name    The name, as written (no checks)
     * @param string $type    The type flag
     * @param string $content The content
     * @param string $link    The link target
     * @param int    $mode    The mode
     *
     * @return string
     */
    public static function entry(string $name, string $type = '0', string $content = '', string $link = '', int $mode = 0644): string
    {
        $fields = [
            str_pad(substr($name, 0, 100), 100, "\0"),
            sprintf('%07o', $mode) . "\0",
            sprintf('%07o', 0) . "\0",
            sprintf('%07o', 0) . "\0",
            sprintf('%011o', strlen($content)) . "\0",
            sprintf('%011o', 0) . "\0",
            str_repeat(' ', 8),
            $type,
            str_pad($link, 100, "\0"),
            "ustar\0" . '00',
        ];
        $header = str_pad(implode('', $fields), 512, "\0");
        $sum    = 0;
        foreach (count_chars($header, 1) as $byte => $count) {
            $sum += $byte * $count;
        }
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);

        return $header . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
    }

    /**
     * Write entries as a gzip-compressed tar with its end blocks
     *
     * @param string   $path    The archive file
     * @param string[] $entries Raw entries (see entry())
     *
     * @return string The path
     */
    public static function archive(string $path, array $entries): string
    {
        file_put_contents($path, (string) gzencode(implode('', $entries) . str_repeat("\0", 1024)));

        return $path;
    }
}
