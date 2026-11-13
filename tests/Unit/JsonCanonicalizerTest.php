<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\JsonCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonCanonicalizerTest extends TestCase
{
    /**
     * RFC 8785 Appendix B (selection) and ECMAScript Number::toString edge cases.
     */
    #[DataProvider('numbers')]
    public function testNumbers(float $value, string $expected): void
    {
        self::assertSame($expected, JsonCanonicalizer::encodeNumber($value));
    }

    public static function numbers(): iterable
    {
        yield [0.0, '0'];
        yield [-0.0, '0'];
        yield [1.0, '1'];
        yield [4.5, '4.5'];
        yield [0.002, '0.002'];
        yield [0.000001, '0.000001'];
        yield [1e-7, '1e-7'];
        yield [1e21, '1e+21'];
        yield [1e20, '100000000000000000000'];
        yield [1e30, '1e+30'];
        yield [333333333.33333329, '333333333.3333333'];
        yield [-1.5e-9, '-1.5e-9'];
        yield [9007199254740992.0, '9007199254740992'];
        yield [295147905179352830000.0, '295147905179352830000'];
        yield [5e-324, '5e-324'];
        yield [1.7976931348623157e308, '1.7976931348623157e+308'];
        yield [0.1, '0.1'];
        yield [0.75, '0.75'];
    }

    public function testRfc8785Example(): void
    {
        $input = [
            'numbers' => [333333333.33333329, 1E30, 4.50, 2e-3, 0.000000000000000000000000001],
            'string' => "\u{20ac}\$\u{000F}\nA'B\"\\\\\"/",
            'literals' => [null, true, false],
        ];
        self::assertSame(
            '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA\'B\"\\\\\\\\\"/"}',
            JsonCanonicalizer::canonicalize($input),
        );
    }

    public function testKeysAreSortedByUtf16CodeUnits(): void
    {
        $object = ["\u{20ac}" => 'Euro Sign', "\r" => 'Carriage Return', "\u{fb33}" => 'Hebrew Letter Dalet With Dagesh', '1' => 'One', "\u{1f600}" => 'Emoji: Grinning Face', "\u{0080}" => 'Control', "\u{00f6}" => 'Latin Small Letter O With Diaeresis'];
        $expectedOrder = ["\r", '1', "\u{0080}", "\u{00f6}", "\u{20ac}", "\u{1f600}", "\u{fb33}"];
        $decoded = json_decode(JsonCanonicalizer::canonicalize($object), true);
        self::assertSame($expectedOrder, array_map('strval', array_keys($decoded)));
    }

    public function testEmptyObjectAndList(): void
    {
        self::assertSame('{"a":[],"b":{}}', JsonCanonicalizer::canonicalize(['b' => new \stdClass(), 'a' => []]));
    }

    public function testRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonCanonicalizer::canonicalize(["\xff" => 1]);
    }

    public function testRejectsUnsafeIntegers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonCanonicalizer::canonicalize(['n' => \PHP_INT_MAX]);
    }

    public function testRejectsNan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonCanonicalizer::canonicalize(['n' => \NAN]);
    }
}
