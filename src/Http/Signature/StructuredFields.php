<?php

declare(strict_types=1);

namespace App\Http\Signature;

/**
 * Minimal, strict RFC 8941 (Structured Field Values) parser and serializer, covering what
 * RFC 9421 (Signature-Input, Signature) and RFC 9530 (Content-Digest) need.
 *
 * Members are represented as ['value' => bare item | list<member>, 'params' => array<string, bare item>].
 * Tokens are SfToken instances, byte sequences SfBytes instances.
 */
final class StructuredFields
{
    private const MAX_INPUT = 8192;

    private string $input = '';
    private int $pos = 0;

    /**
     * @return array<string, array{value: mixed, params: array<string, mixed>}>
     */
    public static function parseDictionary(string $input): array
    {
        $parser = new self();
        if (\strlen($input) > self::MAX_INPUT) {
            throw new \InvalidArgumentException('Structured field too long.');
        }
        $parser->input = $input;
        $parser->pos = 0;
        $parser->skipSp();

        $dict = [];
        while (!$parser->eof()) {
            $key = $parser->parseKey();
            if ($parser->peek('=')) {
                ++$parser->pos;
                $member = $parser->parseItemOrInnerList();
            } else {
                $member = ['value' => true, 'params' => $parser->parseParameters()];
            }
            $dict[$key] = $member; // later duplicates overwrite earlier ones (RFC 8941 4.2.2)
            $parser->skipOws();
            if ($parser->eof()) {
                return $dict;
            }
            $parser->expect(',');
            $parser->skipOws();
            if ($parser->eof()) {
                throw new \InvalidArgumentException('Trailing comma in dictionary.');
            }
        }

        return $dict;
    }

    /**
     * @param list<array{value: mixed, params: array<string, mixed>}> $items
     * @param array<string, mixed>                                     $params
     */
    public static function serializeInnerList(array $items, array $params): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = self::serializeBareItem($item['value']).self::serializeParameters($item['params']);
        }

        return '('.implode(' ', $parts).')'.self::serializeParameters($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function serializeParameters(array $params): string
    {
        $out = '';
        foreach ($params as $key => $value) {
            $out .= ';'.$key;
            if (true !== $value) {
                $out .= '='.self::serializeBareItem($value);
            }
        }

        return $out;
    }

    public static function serializeBareItem(mixed $value): string
    {
        return match (true) {
            \is_int($value) => (string) $value,
            \is_bool($value) => $value ? '?1' : '?0',
            $value instanceof SfToken => $value->value,
            $value instanceof SfBytes => ':'.base64_encode($value->bytes).':',
            \is_string($value) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            \is_float($value) => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0',
            default => throw new \InvalidArgumentException('Cannot serialize value.'),
        };
    }

    /**
     * @return array{value: mixed, params: array<string, mixed>}
     */
    private function parseItemOrInnerList(): array
    {
        if ($this->peek('(')) {
            return $this->parseInnerList();
        }

        return ['value' => $this->parseBareItem(), 'params' => $this->parseParameters()];
    }

    /**
     * @return array{value: list<array{value: mixed, params: array<string, mixed>}>, params: array<string, mixed>}
     */
    private function parseInnerList(): array
    {
        $this->expect('(');
        $items = [];
        while (!$this->eof()) {
            $this->skipSp();
            if ($this->peek(')')) {
                ++$this->pos;

                return ['value' => $items, 'params' => $this->parseParameters()];
            }
            $items[] = ['value' => $this->parseBareItem(), 'params' => $this->parseParameters()];
            $following = $this->input[$this->pos] ?? '';
            if (' ' !== $following && ')' !== $following) {
                throw new \InvalidArgumentException('Malformed inner list.');
            }
        }
        throw new \InvalidArgumentException('Unterminated inner list.');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseParameters(): array
    {
        $params = [];
        while ($this->peek(';')) {
            ++$this->pos;
            $this->skipSp();
            $key = $this->parseKey();
            $value = true;
            if ($this->peek('=')) {
                ++$this->pos;
                $value = $this->parseBareItem();
            }
            $params[$key] = $value;
        }

        return $params;
    }

    private function parseKey(): string
    {
        if (1 !== preg_match('/\G[a-z*][a-z0-9_.*-]*/', $this->input, $m, 0, $this->pos)) {
            throw new \InvalidArgumentException('Invalid key.');
        }
        $this->pos += \strlen($m[0]);

        return $m[0];
    }

    private function parseBareItem(): mixed
    {
        $c = $this->input[$this->pos] ?? '';

        if ('-' === $c || ctype_digit($c)) {
            if (1 !== preg_match('/\G-?\d{1,15}(\.\d{1,3})?/', $this->input, $m, 0, $this->pos)) {
                throw new \InvalidArgumentException('Invalid number.');
            }
            $this->pos += \strlen($m[0]);

            return isset($m[1]) ? (float) $m[0] : (int) $m[0];
        }
        if ('"' === $c) {
            ++$this->pos;
            $out = '';
            while (!$this->eof()) {
                $ch = $this->input[$this->pos++];
                if ('\\' === $ch) {
                    if ($this->eof()) {
                        throw new \InvalidArgumentException('Unterminated escape in string.');
                    }
                    $next = $this->input[$this->pos++];
                    if ('"' !== $next && '\\' !== $next) {
                        throw new \InvalidArgumentException('Invalid escape in string.');
                    }
                    $out .= $next;
                } elseif ('"' === $ch) {
                    return $out;
                } elseif (\ord($ch) < 0x20 || \ord($ch) > 0x7E) {
                    throw new \InvalidArgumentException('Invalid character in string.');
                } else {
                    $out .= $ch;
                }
            }
            throw new \InvalidArgumentException('Unterminated string.');
        }
        if (':' === $c) {
            if (1 !== preg_match('/\G:([A-Za-z0-9+\/=]*):/', $this->input, $m, 0, $this->pos)) {
                throw new \InvalidArgumentException('Invalid byte sequence.');
            }
            $this->pos += \strlen($m[0]);
            $bytes = base64_decode($m[1], true);
            if (false === $bytes) {
                throw new \InvalidArgumentException('Invalid base64 in byte sequence.');
            }

            return new SfBytes($bytes);
        }
        if ('?' === $c) {
            $v = $this->input[$this->pos + 1] ?? '';
            if ('0' !== $v && '1' !== $v) {
                throw new \InvalidArgumentException('Invalid boolean.');
            }
            $this->pos += 2;

            return '1' === $v;
        }
        if (1 === preg_match('/\G[A-Za-z*][!#$%&\'*+\-.^_`|~0-9A-Za-z:\/]*/', $this->input, $m, 0, $this->pos)) {
            $this->pos += \strlen($m[0]);

            return new SfToken($m[0]);
        }
        throw new \InvalidArgumentException('Invalid bare item.');
    }

    private function eof(): bool
    {
        return $this->pos >= \strlen($this->input);
    }

    private function peek(string $char): bool
    {
        return ($this->input[$this->pos] ?? null) === $char;
    }

    private function expect(string $char): void
    {
        if (!$this->peek($char)) {
            throw new \InvalidArgumentException(\sprintf('Expected "%s".', $char));
        }
        ++$this->pos;
    }

    private function skipSp(): void
    {
        while ($this->peek(' ')) {
            ++$this->pos;
        }
    }

    private function skipOws(): void
    {
        while ($this->peek(' ') || $this->peek("\t")) {
            ++$this->pos;
        }
    }
}
