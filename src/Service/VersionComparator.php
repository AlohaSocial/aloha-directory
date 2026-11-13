<?php

declare(strict_types=1);

namespace App\Service;

final class VersionComparator
{
    /**
     * Compares the numeric major.minor.patch core of two semantic versions; pre-release tags count
     * as lower than the release.
     */
    public static function atLeast(string $version, string $minimum): bool
    {
        return version_compare(self::normalize($version), self::normalize($minimum), '>=');
    }

    private static function normalize(string $version): string
    {
        if (1 !== preg_match('/\A(\d+)\.(\d+)(?:\.(\d+))?(-[0-9A-Za-z.-]+)?/', trim($version), $m)) {
            return '0.0.0-invalid';
        }

        return $m[1].'.'.$m[2].'.'.($m[3] ?? '0').(isset($m[4]) ? '-'.ltrim($m[4], '-') : '');
    }
}
