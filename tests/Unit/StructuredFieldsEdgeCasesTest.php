<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Signature\SfBytes;
use App\Http\Signature\SfToken;
use App\Http\Signature\StructuredFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuredFieldsEdgeCasesTest extends TestCase
{
    public function testBareItemTypes(): void
    {
        $dict = StructuredFields::parseDictionary('i=-42, d=3.141, s="a \\"q\\" \\\\ b", t=foo/bar:baz, b=:AQID:, y=?1, n=?0, flag, e=""');

        self::assertSame(-42, $dict['i']['value']);
        self::assertSame(3.141, $dict['d']['value']);
        self::assertSame('a "q" \\ b', $dict['s']['value']);
        self::assertInstanceOf(SfToken::class, $dict['t']['value']);
        self::assertSame('foo/bar:baz', $dict['t']['value']->value);
        self::assertInstanceOf(SfBytes::class, $dict['b']['value']);
        self::assertSame("\1\2\3", $dict['b']['value']->bytes);
        self::assertTrue($dict['y']['value']);
        self::assertFalse($dict['n']['value']);
        self::assertSame(['value' => true, 'params' => []], $dict['flag']);
        self::assertSame('', $dict['e']['value']);
    }

    public function testParametersOnMembersAndInnerListItems(): void
    {
        $dict = StructuredFields::parseDictionary('a=("x";p=1 "y" z;q);r=?0;s, b;c=5;d');

        self::assertEquals([['value' => 'x', 'params' => ['p' => 1]], ['value' => 'y', 'params' => []], ['value' => new SfToken('z'), 'params' => ['q' => true]]], $dict['a']['value']);
        self::assertSame(['r' => false, 's' => true], $dict['a']['params']);
        self::assertSame(['value' => true, 'params' => ['c' => 5, 'd' => true]], $dict['b']);
    }

    public function testWhitespaceRules(): void
    {
        self::assertSame(['a', 'b', 'c'], array_keys(StructuredFields::parseDictionary("  a=1,\tb=2 ,  c=3")));
        self::assertSame(['x', 'y'], array_column(StructuredFields::parseDictionary('l=(  "x"   "y"  )')['l']['value'], 'value'));
        self::assertSame([], StructuredFields::parseDictionary(''));
        self::assertSame([], StructuredFields::parseDictionary('   '));
        self::assertSame([], StructuredFields::parseDictionary('l=()')['l']['value']);
    }

    public function testLaterDuplicateKeysWin(): void
    {
        $dict = StructuredFields::parseDictionary('sig=:AQ==:, other=1, sig=:Ag==:');
        self::assertSame(['sig', 'other'], array_keys($dict));
        self::assertSame("\2", $dict['sig']['value']->bytes);
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedInput(string $header): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructuredFields::parseDictionary($header);
    }

    public static function malformed(): iterable
    {
        yield 'leading comma' => [',a=1'];
        yield 'double comma' => ['a=1,,b=2'];
        yield 'missing comma' => ['a=1 b=2'];
        yield 'key starting with digit' => ['1a=1'];
        yield 'key with upper case' => ['aB=1'];
        yield 'empty value' => ['a='];
        yield 'sixteen-digit integer' => ['a=1234567890123456'];
        yield 'four decimal places' => ['a=1.2345'];
        yield 'trailing dot' => ['a=1.'];
        yield 'lone minus' => ['a=-'];
        yield 'invalid escape' => ['a="\\n"'];
        yield 'unterminated escape' => ['a="abc\\'];
        yield 'non-ascii in string' => ['a="ä"'];
        yield 'tab in string' => ["a=\"a\tb\""];
        yield 'boolean ?2' => ['a=?2'];
        yield 'boolean ?' => ['a=?'];
        yield 'unterminated bytes' => ['a=:AQID'];
        yield 'base64url in bytes' => ['a=:-_8=:'];
        yield 'items not separated by space' => ['a=("x""y")'];
        yield 'nested inner list' => ['a=(("x"))'];
        yield 'inner list without close' => ['a=("x" "y" '];
        yield 'parameter with upper case key' => ['a=1;P=2'];
        yield 'parameter without key' => ['a=1;=2'];
        yield 'parameter value inner list' => ['a=1;p=(1)'];
        yield 'garbage after item' => ['a=1x'];
        yield 'trailing whitespace then comma' => ['a=1 , '];
        yield 'token starting with a symbol' => ['a=%x'];
        yield 'just over the size limit' => ['a='.str_repeat('1', 8191)];
    }

    public function testTrueParametersSerializeCanonically(): void
    {
        // RFC 8941 4.1.1.2: a parameter whose value is true is written as a bare key.
        $dict = StructuredFields::parseDictionary('sig=("a");z=?1');
        self::assertSame('("a");z', StructuredFields::serializeInnerList($dict['sig']['value'], $dict['sig']['params']));
    }

    public function testSizeLimitIsInclusive(): void
    {
        $header = 'a="'.str_repeat('x', 8188).'"';
        self::assertSame(8192, \strlen($header));
        self::assertSame(8188, \strlen(StructuredFields::parseDictionary($header)['a']['value']));
    }

    #[DataProvider('bareItems')]
    public function testSerializeBareItem(mixed $value, string $expected): void
    {
        self::assertSame($expected, StructuredFields::serializeBareItem($value));
    }

    public static function bareItems(): iterable
    {
        yield 'int' => [1618884473, '1618884473'];
        yield 'negative int' => [-3, '-3'];
        yield 'true' => [true, '?1'];
        yield 'false' => [false, '?0'];
        yield 'token' => [new SfToken('ed25519'), 'ed25519'];
        yield 'bytes' => [new SfBytes("\1\2\3"), ':AQID:'];
        yield 'empty bytes' => [new SfBytes(''), '::'];
        yield 'string with quotes and backslash' => ['say "hi" \\o/', '"say \\"hi\\" \\\\o/"'];
        yield 'float' => [2.5, '2.5'];
        yield 'float rounding' => [1.23456, '1.235'];
        yield 'float integral' => [3.0, '3'];
        yield 'float zero' => [0.0, '0'];
    }

    public function testSerializeRejectsUnsupportedValues(): void
    {
        foreach ([null, ['a'], new \stdClass()] as $value) {
            try {
                StructuredFields::serializeBareItem($value);
                self::fail('Serialised an unsupported value.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testParametersSerializeTrueAsBareKey(): void
    {
        self::assertSame(';a;b=?0;c=1;d="x";e=tok', StructuredFields::serializeParameters(['a' => true, 'b' => false, 'c' => 1, 'd' => 'x', 'e' => new SfToken('tok')]));
        self::assertSame('', StructuredFields::serializeParameters([]));
    }

    #[DataProvider('roundTrips')]
    public function testInnerListRoundTrip(string $member): void
    {
        $dict = StructuredFields::parseDictionary('sig='.$member);
        self::assertSame($member, StructuredFields::serializeInnerList($dict['sig']['value'], $dict['sig']['params']));
    }

    public static function roundTrips(): iterable
    {
        yield 'profile' => ['("@method" "@authority" "@path" "content-digest");created=1618884473;keyid="k";alg="ed25519";tag="aloha-directory";nonce="abc"'];
        yield 'empty list' => ['();created=1'];
        yield 'item parameters' => ['("a";x=1 "b";y);z;w=?0'];
        yield 'escaped string' => ['("q\\"uote");keyid="back\\\\slash"'];
        yield 'bytes and token' => ['(:AQID: tok);p=:AA==:'];
    }
}
