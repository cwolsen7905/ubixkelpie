<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Psr\Log\LoggerInterface as Logger;

/**
 * Packs a checkout into a gzip-compressed tar, and unpacks one into an empty directory, refusing what could escape it
 *
 * Written in PHP (POSIX ustar, with pax headers for long names) rather than shelling out to `tar`, so the worker starts
 * no new program and both ends agree on exactly one format. Only regular files, directories and symbolic links are
 * written or accepted; file modes are reduced to 0644 or 0755.
 *
 * Unpacking refuses an absolute path, a `..` component, a NUL, any other entry type (hard links, devices, FIFOs), a
 * symbolic link whose target is absolute or leads out of the directory, more bytes or entries than allowed, and a
 * damaged header. Links are created after every file is written, so no file is ever written through one.
 *
 * @see \Kelpie\Tests\Sandbox\TarArchiveTest PHPUnit test case
 */
final class TarArchive
{
    private const int BLOCK = 512;

    private const int MAX_PAX_BYTES = 65536;

    private const int MAX_FILE_BYTES = 8589934591;

    /**
     * The links of the archive being unpacked, each its path and target: created after every file
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $links = [];

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
     * Pack a directory
     *
     * @param string   $directory The directory; its contents become the archive's top level
     * @param string   $archive   The archive file to write
     * @param string[] $skip      Names at the top level to leave out
     *
     * @return string Why it could not be packed, empty when it was
     */
    public function pack(string $directory, string $archive, array $skip = []): string
    {
        if (!is_dir($directory)) {
            return 'the checkout is not a directory';
        }

        $out = gzopen($archive, 'wb6');
        if ($out === false) {
            return 'could not create the archive';
        }

        $error = $this->packDirectory($out, rtrim($directory, '/'), '', $skip);
        if ($error === '' && gzwrite($out, str_repeat("\0", self::BLOCK * 2)) === false) {
            $error = 'could not write the archive';
        }
        gzclose($out);

        if ($error !== '') {
            $this->logger->warning('Gate archive not packed', ['reason' => $error]);
        }

        return $error;
    }

    /**
     * Unpack into an empty directory
     *
     * @param string $archive    The archive
     * @param string $target     The directory, which must exist and be empty
     * @param int    $maxBytes   The most file content it may unpack to
     * @param int    $maxEntries The most entries it may hold
     *
     * @return string Why it was refused, empty when it was unpacked
     */
    public function unpack(string $archive, string $target, int $maxBytes, int $maxEntries): string
    {
        $root = realpath($target);
        if ($root === false || !is_dir($root) || (scandir($root) ?: []) !== ['.', '..']) {
            return 'the target is not an empty directory';
        }

        $in = gzopen($archive, 'rb');
        if ($in === false) {
            return 'could not open the archive';
        }

        $this->links = [];
        $error       = $this->unpackEntries($in, $root, $maxBytes, $maxEntries);
        gzclose($in);

        if ($error === '') {
            $error = $this->createLinks($root);
        }
        $this->links = [];
        if ($error !== '') {
            $this->logger->warning('Gate archive refused', ['reason' => $error]);
        }

        return $error;
    }

    /**
     * Write one directory's entries, depth first, in name order
     *
     * @param mixed    $out       The gzip stream (a resource)
     * @param string   $directory The directory on disk
     * @param string   $prefix    Its path in the archive, '' or ending in '/'
     * @param string[] $skip      Names to leave out at the top level
     *
     * @return string Why it could not, empty when it did
     */
    private function packDirectory(mixed $out, string $directory, string $prefix, array $skip): string
    {
        $names = is_readable($directory) ? scandir($directory) : false;
        if ($names === false) {
            return 'could not read ' . ($prefix === '' ? 'the checkout' : $prefix);
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || ($prefix === '' && in_array($name, $skip, true))) {
                continue;
            }

            $path  = $directory . '/' . $name;
            $entry = $prefix . $name;
            $error = match (true) {
                is_link($path) => $this->writeEntry($out, $entry, '2', 0777, 0, (string) readlink($path)),
                is_dir($path)  => $this->writeEntry($out, $entry . '/', '5', 0755, 0) ?: $this->packDirectory($out, $path, $entry . '/', []),
                is_file($path) => $this->packFile($out, $path, $entry),
                default        => '',
            };
            if ($error !== '') {
                return $error;
            }
        }

        return '';
    }

    /**
     * Write a regular file: its header, its content, the padding to a whole block
     *
     * @param mixed  $out   The gzip stream (a resource)
     * @param string $path  The file on disk
     * @param string $entry Its path in the archive
     *
     * @return string Why it could not, empty when it did
     */
    private function packFile(mixed $out, string $path, string $entry): string
    {
        $size = filesize($path);
        $in   = fopen($path, 'rb');
        if ($size === false || $size > self::MAX_FILE_BYTES || $in === false || !is_resource($out)) {
            return 'could not read ' . $entry;
        }

        $mode   = ((fileperms($path) ?: 0) & 0100) === 0 ? 0644 : 0755;
        $error  = $this->writeEntry($out, $entry, '0', $mode, $size);
        $copied = $error === '' ? stream_copy_to_stream($in, $out) : 0;
        fclose($in);
        if ($error !== '' || $copied !== $size) {
            return $error ?: 'could not read ' . $entry;
        }

        return gzwrite($out, $this->padding($size)) === false ? 'could not write the archive' : '';
    }

    /**
     * Write a header, preceded by a pax header when the path or link target is too long for ustar
     *
     * @param mixed  $out  The gzip stream (a resource)
     * @param string $path The entry's path
     * @param string $type The ustar type flag
     * @param int    $mode The mode
     * @param int    $size The content size
     * @param string $link The link target, for a symbolic link
     *
     * @return string Why it could not, empty when it did
     */
    private function writeEntry(mixed $out, string $path, string $type, int $mode, int $size, string $link = ''): string
    {
        if (!is_resource($out)) {
            return 'could not write the archive';
        }

        $pax = (strlen($path) > 100 ? $this->paxRecord('path', $path) : '') . (strlen($link) > 100 ? $this->paxRecord('linkpath', $link) : '');
        $raw = $pax === '' ? '' : $this->header('././@PaxHeader', 'x', 0644, strlen($pax), '') . $pax . $this->padding(strlen($pax));

        return gzwrite($out, $raw . $this->header(substr($path, 0, 100), $type, $mode, $size, substr($link, 0, 100))) === false ? 'could not write the archive' : '';
    }

    /**
     * One ustar header block
     *
     * @param string $name The name field (at most 100 bytes)
     * @param string $type The type flag
     * @param int    $mode The mode
     * @param int    $size The content size
     * @param string $link The link field (at most 100 bytes)
     *
     * @return string
     */
    private function header(string $name, string $type, int $mode, int $size, string $link): string
    {
        $zero   = sprintf('%07o', 0) . "\0";
        $fields = [
            str_pad($name, 100, "\0"),
            sprintf('%07o', $mode) . "\0",
            $zero,
            $zero,
            sprintf('%011o', $size) . "\0",
            sprintf('%011o', 0) . "\0",
            str_repeat(' ', 8),
            $type,
            str_pad($link, 100, "\0"),
            "ustar\0" . '00',
        ];
        $header = str_pad(implode('', $fields), self::BLOCK, "\0");

        return substr_replace($header, sprintf('%06o', $this->checksum($header)) . "\0 ", 148, 8);
    }

    /**
     * One pax record: "<length> <key>=<value>\n", where the length counts itself
     *
     * @param string $key   The key
     * @param string $value The value
     *
     * @return string
     */
    private function paxRecord(string $key, string $value): string
    {
        $body   = ' ' . $key . '=' . $value . "\n";
        $size   = strlen($body);
        $length = $size + strlen((string) $size);
        // The length counts its own digits: adding them can carry it to one digit more
        if (strlen((string) $length) > strlen((string) $size)) {
            $length++;
        }

        return $length . $body;
    }

    /**
     * Read every entry: files and directories are written, links are collected for afterwards
     *
     * @param mixed  $in         The gzip stream (a resource)
     * @param string $root       The real path of the target directory
     * @param int    $maxBytes   The most file content
     * @param int    $maxEntries The most entries
     *
     * @return string Why it was refused, empty when it was unpacked
     */
    private function unpackEntries(mixed $in, string $root, int $maxBytes, int $maxEntries): string
    {
        $pax     = [];
        $bytes   = 0;
        $entries = 0;

        while (true) {
            $block = $this->readExactly($in, self::BLOCK);
            if ($block === null) {
                return 'the archive ends early';
            }
            if ($block === str_repeat("\0", self::BLOCK)) {
                return '';
            }
            $entries++;
            if ($entries > $maxEntries) {
                return 'the archive has more than ' . $maxEntries . ' entries';
            }

            $header = $this->parseHeader($block);
            if ($header === null) {
                return 'the archive has a damaged header';
            }
            [$name, $type, $mode, $size, $link] = $header;

            if ($type === 'x') {
                $records = $size <= self::MAX_PAX_BYTES ? $this->readExactly($in, $size + strlen($this->padding($size))) : null;
                if ($records === null) {
                    return 'the archive has a damaged extended header';
                }
                $pax = $this->parsePax(substr($records, 0, $size));

                continue;
            }

            $name = $pax['path'] ?? $name;
            $link = $pax['linkpath'] ?? $link;
            $pax  = [];

            $bytes += $size;
            if ($bytes > $maxBytes) {
                return 'the archive unpacks to more than ' . $maxBytes . ' bytes';
            }

            $relative = $this->safePath($name);
            if ($relative === null) {
                return 'an archive entry leaves the work directory: ' . $this->printable($name);
            }

            $error = match ($type) {
                '5'       => $this->makeDirectory($root, $relative),
                '0', "\0" => $this->writeFile($in, $root, $relative, $mode, $size),
                '2'       => $size === 0 ? $this->collectLink($relative, $link) : 'a link entry has content: ' . $this->printable($name),
                default   => 'an archive entry is not a file, directory or link: ' . $this->printable($name),
            };
            if ($error !== '') {
                return $error;
            }
        }
    }

    /**
     * The fields of a ustar header, or null when it is not one (bad checksum, magic or number)
     *
     * @param string $block The header block
     *
     * @return ?array{0: string, 1: string, 2: int, 3: int, 4: string} Name, type, mode, size, link target
     */
    private function parseHeader(string $block): ?array
    {
        $stored = trim(substr($block, 148, 8), " \0");
        $size   = trim(substr($block, 124, 12), " \0");
        $mode   = trim(substr($block, 100, 8), " \0");
        if (substr($block, 257, 5) !== 'ustar') {
            return null;
        }
        foreach ([$stored, $size, $mode] as $number) {
            if (preg_match('/^[0-7]{1,12}$/', $number) !== 1) {
                return null;
            }
        }
        if (octdec($stored) !== $this->checksum(substr_replace($block, str_repeat(' ', 8), 148, 8))) {
            return null;
        }

        $name   = rtrim(substr($block, 0, 100), "\0");
        $prefix = rtrim(substr($block, 345, 155), "\0");

        return [
            $prefix === '' ? $name : $prefix . '/' . $name,
            $block[156],
            (int) octdec($mode),
            (int) octdec($size),
            rtrim(substr($block, 157, 100), "\0"),
        ];
    }

    /**
     * The path and linkpath of a pax extended header; other keys are ignored
     *
     * @param string $records The records
     *
     * @return array<string, string>
     */
    private function parsePax(string $records): array
    {
        $values = [];
        while ($records !== '' && preg_match('/^(\d+) /', $records, $match) === 1) {
            $length = (int) $match[1];
            if ($length <= strlen($match[0]) || $length > strlen($records)) {
                break;
            }
            $record        = substr($records, strlen($match[0]), $length - strlen($match[0]) - 1);
            $records       = substr($records, $length);
            [$key, $value] = array_pad(explode('=', $record, 2), 2, '');
            if ($key === 'path' || $key === 'linkpath') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * The path relative to the target, or null when it is absolute, has a `..` component or a NUL
     *
     * @param string $name The entry's name
     *
     * @return ?string '' for the target itself
     */
    private function safePath(string $name): ?string
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0")) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return null;
            }
            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * Create a directory inside the target
     *
     * @param string $root     The target's real path
     * @param string $relative The directory, relative to it
     *
     * @return string Why it could not, empty when it did
     */
    private function makeDirectory(string $root, string $relative): string
    {
        $path = $root . '/' . $relative;
        if ($relative === '' || (is_dir($path) && !is_link($path))) {
            return '';
        }
        if (file_exists($path) || is_link($path) || !mkdir($path, 0755, true)) {
            return 'could not create the directory ' . $this->printable($relative);
        }

        return $this->insideRoot($root, $path) ? '' : 'an archive entry leaves the work directory: ' . $this->printable($relative);
    }

    /**
     * Write one file's content from the archive
     *
     * @param mixed  $in       The gzip stream (a resource)
     * @param string $root     The target's real path
     * @param string $relative The file, relative to it
     * @param int    $mode     Its mode in the archive (only the owner's execute bit is kept)
     * @param int    $size     Its size
     *
     * @return string Why it could not, empty when it did
     */
    private function writeFile(mixed $in, string $root, string $relative, int $mode, int $size): string
    {
        $path  = $root . '/' . $relative;
        $error = $relative === '' ? 'a file entry has no name' : $this->makeDirectory($root, dirname($relative) === '.' ? '' : dirname($relative));
        if ($error === '' && (is_dir($path) || is_link($path))) {
            $error = 'an archive entry is both a file and a directory: ' . $this->printable($relative);
        }
        $out = $error === '' && is_resource($in) ? fopen($path, 'wb') : false;
        if ($out === false) {
            return $error ?: 'could not write ' . $this->printable($relative);
        }

        $copied = $size === 0 ? 0 : stream_copy_to_stream($in, $out, $size);
        fclose($out);
        if ($copied !== $size || $this->readExactly($in, strlen($this->padding($size))) === null) {
            return 'the archive ends early';
        }

        return chmod($path, ($mode & 0100) === 0 ? 0644 : 0755) ? '' : 'could not write ' . $this->printable($relative);
    }

    /**
     * Note a symbolic link for later, refusing an absolute target or one that leads out of the target
     *
     * @param string $relative The link, relative to the target
     * @param string $target   What it points at
     *
     * @return string Why it was refused, empty when it was noted
     */
    private function collectLink(string $relative, string $target): string
    {
        $depth = substr_count($relative, '/');
        foreach (explode('/', $target) as $part) {
            $depth += match ($part) {
                '..'      => -1,
                '', '.'   => 0,
                default   => 1,
            };
            if ($depth < 0) {
                break;
            }
        }
        if ($relative === '' || $target === '' || str_starts_with($target, '/') || str_contains($target, "\0") || $depth < 0) {
            return 'a link leaves the work directory: ' . $this->printable($relative);
        }

        $this->links[] = [$relative, $target];

        return '';
    }

    /**
     * Create the links, after every file, each in a real directory inside the target and resolving inside it
     *
     * @param string $root The target's real path
     *
     * @return string Why it was refused, empty when they were created
     */
    private function createLinks(string $root): string
    {
        foreach ($this->links as [$relative, $target]) {
            $parent = dirname($relative) === '.' ? '' : dirname($relative);
            $error  = $this->makeDirectory($root, $parent);
            $path   = $root . '/' . $relative;
            $real   = realpath($root . ($parent === '' ? '' : '/' . $parent));
            if ($error !== '' || $real !== $root . ($parent === '' ? '' : '/' . $parent) || file_exists($path) || is_link($path) || !symlink($target, $path)) {
                return $error ?: 'could not create the link ' . $this->printable($relative);
            }
            if (realpath($path) !== false && !$this->insideRoot($root, $path)) {
                unlink($path);

                return 'a link leaves the work directory: ' . $this->printable($relative);
            }
        }

        return '';
    }

    /**
     * Whether a path's real location is the target or inside it
     *
     * @param string $root The target's real path
     * @param string $path The path
     *
     * @return bool
     */
    private function insideRoot(string $root, string $path): bool
    {
        $real = realpath($path);

        return $real !== false && ($real === $root || str_starts_with($real, $root . '/'));
    }

    /**
     * Read exactly this many bytes, or null when the stream ends first
     *
     * @param mixed $in     The stream (a resource)
     * @param int   $length The bytes
     *
     * @return ?string
     */
    private function readExactly(mixed $in, int $length): ?string
    {
        $data = '';
        $have = 0;
        while ($have < $length && is_resource($in)) {
            $chunk = fread($in, max(1, min(1048576, $length - $have)));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
            $have  = strlen($data);
        }

        return $have === $length ? $data : null;
    }

    /**
     * The zero bytes that bring content up to a whole block
     *
     * @param int $size The content size
     *
     * @return string
     */
    private function padding(int $size): string
    {
        return str_repeat("\0", (self::BLOCK - $size % self::BLOCK) % self::BLOCK);
    }

    /**
     * The header checksum: the sum of its bytes, with the checksum field counted as spaces
     *
     * @param string $header The header, checksum field already spaces
     *
     * @return int
     */
    private function checksum(string $header): int
    {
        $sum = 0;
        foreach (count_chars($header, 1) as $byte => $count) {
            $sum += $byte * $count;
        }

        return $sum;
    }

    /**
     * A name as it may appear in a reason: printable ASCII, at most 200 characters
     *
     * @param string $name The name
     *
     * @return string
     */
    private function printable(string $name): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $name), 0, 200);
    }
}
