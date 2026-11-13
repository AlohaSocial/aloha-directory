<?php

declare(strict_types=1);

namespace App\Lists;

/**
 * Writes a file so that readers only ever see the old or the new complete version (concept 13.2).
 */
final class AtomicFileWriter
{
    public static function write(string $path, string $contents, int $mode = 0o644): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $dir));
        }
        $tmp = $dir.'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';
        $handle = fopen($tmp, 'x');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $tmp));
        }
        try {
            if (false === fwrite($handle, $contents) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException(\sprintf('Cannot write "%s".', $tmp));
            }
        } finally {
            fclose($handle);
        }
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(\sprintf('Cannot move "%s" into place.', $path));
        }
    }
}
