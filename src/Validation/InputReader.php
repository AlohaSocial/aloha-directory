<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Small, explicit reader for untrusted JSON input. Every accessor validates type and bounds and
 * records an error under a dotted path instead of throwing, so a client gets all problems at once.
 */
final class InputReader
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function error(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }

    public function has(string $path): bool
    {
        return null !== $this->raw($path);
    }

    public function raw(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $path, int $max, bool $required = true, int $min = 1, bool $multiline = false): ?string
    {
        $value = $this->raw($path);
        if (null === $value) {
            if ($required) {
                $this->error($path, 'is required');
            }

            return null;
        }
        if (!\is_string($value)) {
            $this->error($path, 'must be a string');

            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $this->error($path, 'must be valid UTF-8');

            return null;
        }
        $value = self::cleanText($value, $multiline);
        $length = mb_strlen($value);
        if ($length < $min) {
            if ($required || $length > 0) {
                $this->error($path, \sprintf('must have at least %d characters', $min));
            }

            return $required ? null : (0 === $length ? null : $value);
        }
        if ($length > $max) {
            $this->error($path, \sprintf('must have at most %d characters', $max));

            return null;
        }

        return $value;
    }

    public function int(string $path, int $min, int $max, bool $required = true): ?int
    {
        $value = $this->raw($path);
        if (null === $value) {
            if ($required) {
                $this->error($path, 'is required');
            }

            return null;
        }
        if (!\is_int($value)) {
            $this->error($path, 'must be an integer');

            return null;
        }
        if ($value < $min || $value > $max) {
            $this->error($path, \sprintf('must be between %d and %d', $min, $max));

            return null;
        }

        return $value;
    }

    public function float(string $path, float $min, float $max, bool $required = true): ?float
    {
        $value = $this->raw($path);
        if (null === $value) {
            if ($required) {
                $this->error($path, 'is required');
            }

            return null;
        }
        if (!\is_int($value) && !\is_float($value)) {
            $this->error($path, 'must be a number');

            return null;
        }
        $value = (float) $value;
        if (is_nan($value) || $value < $min || $value > $max) {
            $this->error($path, \sprintf('must be between %s and %s', $min, $max));

            return null;
        }

        return $value;
    }

    public function bool(string $path, bool $required = true): ?bool
    {
        $value = $this->raw($path);
        if (null === $value) {
            if ($required) {
                $this->error($path, 'is required');
            }

            return null;
        }
        if (!\is_bool($value)) {
            $this->error($path, 'must be true or false');

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $path, array $allowed, bool $required = true): ?string
    {
        $value = $this->raw($path);
        if (null === $value) {
            if ($required) {
                $this->error($path, 'is required');
            }

            return null;
        }
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            $this->error($path, 'must be one of: '.implode(', ', $allowed));

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>|null
     */
    public function subset(string $path, array $allowed, bool $required = true): ?array
    {
        $value = $this->raw($path);
        if (null === $value) {
            if ($required) {
                $this->error($path, 'is required');
            }

            return null;
        }
        if (!\is_array($value) || !array_is_list($value)) {
            $this->error($path, 'must be a list');

            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (!\is_string($item) || !\in_array($item, $allowed, true)) {
                $this->error($path, 'may only contain: '.implode(', ', $allowed));

                return null;
            }
            $out[$item] = true;
        }
        $result = array_keys($out);
        sort($result);

        return $result;
    }

    /**
     * An absolute https URL without credentials, at most 2,000 characters.
     */
    public function url(string $path, bool $required = true): ?string
    {
        $value = $this->string($path, 2000, $required);
        if (null === $value) {
            return null;
        }
        $parts = parse_url($value);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? null) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || 1 === preg_match('/[\s<>"\\\\^`{|}]/', $value)) {
            $this->error($path, 'must be an absolute https:// URL');

            return null;
        }

        return $value;
    }

    public function email(string $path, bool $required = true): ?string
    {
        $value = $this->string($path, 254, $required, 3);
        if (null === $value) {
            return null;
        }
        if (false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            $this->error($path, 'must be an e-mail address');

            return null;
        }

        return mb_strtolower($value);
    }

    /**
     * @return list<string>|null
     */
    public function stringList(string $path, int $maxItems, int $maxLength, bool $required = true, int $minItems = 1): ?array
    {
        $value = $this->raw($path);
        if (null === $value) {
            if ($required) {
                $this->error($path, 'is required');
            }

            return null;
        }
        if (!\is_array($value) || !array_is_list($value)) {
            $this->error($path, 'must be a list');

            return null;
        }
        if (\count($value) < $minItems || \count($value) > $maxItems) {
            $this->error($path, \sprintf('must have between %d and %d items', $minItems, $maxItems));

            return null;
        }
        $out = [];
        foreach ($value as $i => $item) {
            if (!\is_string($item) || !mb_check_encoding($item, 'UTF-8')) {
                $this->error($path.'.'.$i, 'must be a string');

                return null;
            }
            $item = self::cleanText($item, true);
            if ('' === $item || mb_strlen($item) > $maxLength) {
                $this->error($path.'.'.$i, \sprintf('must have between 1 and %d characters', $maxLength));

                return null;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Strips control and bidi-override characters and normalises whitespace.
     */
    public static function cleanText(string $value, bool $multiline): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        // C0/C1 controls except tab/newline, plus bidi overrides/isolates that can spoof displayed text.
        $value = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value);
        $value = str_replace("\r\n", "\n", $value);
        if (!$multiline) {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        } else {
            $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);
        }

        return trim($value);
    }
}
