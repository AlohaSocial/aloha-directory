<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * JSON Canonicalization Scheme (RFC 8785).
 *
 * PHP lists serialise as JSON arrays, associative arrays and stdClass as JSON objects.
 * An empty PHP array serialises as [] — use new \stdClass() for an empty object.
 */
final class JsonCanonicalizer
{
    private const MAX_DEPTH = 64;
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public static function canonicalize(mixed $value): string
    {
        return self::encode($value, 0);
    }

    private static function encode(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('JSON nesting too deep.');
        }

        return match (true) {
            null === $value => 'null',
            true === $value => 'true',
            false === $value => 'false',
            \is_int($value) => self::encodeInt($value),
            \is_float($value) => self::encodeNumber($value),
            \is_string($value) => self::encodeString($value),
            $value instanceof \stdClass => self::encodeObject(get_object_vars($value), $depth),
            \is_array($value) && array_is_list($value) => self::encodeList($value, $depth),
            \is_array($value) => self::encodeObject($value, $depth),
            $value instanceof \JsonSerializable => self::encode($value->jsonSerialize(), $depth),
            default => throw new \InvalidArgumentException('Unsupported type: '.get_debug_type($value)),
        };
    }

    private static function encodeInt(int $value): string
    {
        if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('Integer outside the I-JSON safe range.');
        }

        return (string) $value;
    }

    /**
     * ECMAScript Number::toString for finite doubles (RFC 8785 section 3.2.2.3).
     */
    public static function encodeNumber(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new \InvalidArgumentException('NaN and Infinity are not valid JSON.');
        }
        if (0.0 === $value) {
            return '0'; // also covers -0
        }

        $sign = $value < 0 ? '-' : '';
        [$digits, $n] = self::shortestDigits(abs($value));
        $k = \strlen($digits);

        if ($k <= $n && $n <= 21) {
            return $sign.$digits.str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return $sign.'0.'.str_repeat('0', -$n).$digits;
        }
        $e = $n - 1;
        $exp = 'e'.($e < 0 ? '-' : '+').abs($e);

        return $sign.(1 === $k ? $digits : $digits[0].'.'.substr($digits, 1)).$exp;
    }

    /**
     * Shortest round-trip decimal digits d and exponent n with value = 0.d × 10^n.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $value): array
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $repr = var_export($value, true);
        } finally {
            ini_set('serialize_precision', false === $previous ? '-1' : $previous);
        }

        if (1 !== preg_match('/\A(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?\z/', $repr, $m)) {
            throw new \LogicException('Unexpected float representation: '.$repr);
        }
        $int = $m[1];
        $frac = $m[2] ?? '';
        $exp = isset($m[3]) ? (int) $m[3] : 0;

        $digits = $int.$frac;
        $n = \strlen($int) + $exp;
        $trimmed = ltrim($digits, '0');
        $n -= \strlen($digits) - \strlen($trimmed);
        $digits = rtrim($trimmed, '0');

        return [$digits, $n];
    }

    private static function encodeString(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('String is not valid UTF-8.');
        }

        $out = preg_replace_callback(
            '/[\x00-\x1f"\\\\]/',
            static fn (array $m): string => match ($m[0]) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\x08" => '\\b',
                "\t" => '\\t',
                "\n" => '\\n',
                "\x0c" => '\\f',
                "\r" => '\\r',
                default => \sprintf('\\u%04x', \ord($m[0])),
            },
            $value,
        );

        return '"'.$out.'"';
    }

    /**
     * @param list<mixed> $list
     */
    private static function encodeList(array $list, int $depth): string
    {
        $parts = [];
        foreach ($list as $item) {
            $parts[] = self::encode($item, $depth + 1);
        }

        return '['.implode(',', $parts).']';
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function encodeObject(array $object, int $depth): string
    {
        $keys = [];
        foreach (array_keys($object) as $key) {
            $key = (string) $key;
            if (!mb_check_encoding($key, 'UTF-8')) {
                throw new \InvalidArgumentException('Object key is not valid UTF-8.');
            }
            // Sorting by UTF-16 code units == byte order of the UTF-16BE encoding.
            $keys[$key] = mb_convert_encoding($key, 'UTF-16BE', 'UTF-8');
        }
        uasort($keys, static fn (string $a, string $b): int => strcmp($a, $b));

        $parts = [];
        foreach (array_keys($keys) as $key) {
            $key = (string) $key;
            $parts[] = self::encodeString($key).':'.self::encode($object[$key], $depth + 1);
        }

        return '{'.implode(',', $parts).'}';
    }
}
