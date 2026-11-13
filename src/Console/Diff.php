<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Field-level differences between two JSON documents, for the profile diff and the list build diff.
 */
final class Diff
{
    /**
     * @param array<mixed>|null $old
     * @param array<mixed>|null $new
     *
     * @return list<array{path: string, old: string|null, new: string|null}>
     */
    public static function fields(?array $old, ?array $new): array
    {
        $a = self::flatten($old ?? []);
        $b = self::flatten($new ?? []);
        $paths = array_unique([...array_keys($a), ...array_keys($b)]);
        sort($paths);
        $out = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            if (($a[$path] ?? null) !== ($b[$path] ?? null)) {
                $out[] = ['path' => $path, 'old' => $a[$path] ?? null, 'new' => $b[$path] ?? null];
            }
        }

        return $out;
    }

    /**
     * Compares two list payloads entry by entry (keyed by domain, or kind for non-server entries).
     *
     * @param array<mixed> $old
     * @param array<mixed> $new
     *
     * @return array{added: list<string>, removed: list<string>, changed: array<string, list<array{path: string, old: string|null, new: string|null}>>}
     */
    public static function entries(array $old, array $new): array
    {
        $index = static function (array $payload): array {
            $out = [];
            foreach ((array) ($payload['entries'] ?? []) as $entry) {
                if (\is_array($entry)) {
                    $out[(string) ($entry['domain'] ?? $entry['url'] ?? $entry['kind'] ?? '?')] = $entry;
                }
            }

            return $out;
        };
        $a = $index($old);
        $b = $index($new);
        $changed = [];
        foreach (array_intersect_key($b, $a) as $key => $entry) {
            $fields = self::fields($a[$key], $entry);
            if ([] !== $fields) {
                $changed[(string) $key] = $fields;
            }
        }

        return [
            'added' => array_map('strval', array_keys(array_diff_key($b, $a))),
            'removed' => array_map('strval', array_keys(array_diff_key($a, $b))),
            'changed' => $changed,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value) && !array_is_list($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = \is_string($value) ? $value : json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
        }

        return $out;
    }
}
