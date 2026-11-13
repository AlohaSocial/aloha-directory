<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Signature\SfBytes;
use App\Http\Signature\StructuredFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuredFieldsTest extends TestCase
{
    public function testParsesSignatureInput(): void
    {
        $header = 'dir=("@method" "@authority" "@path" "content-digest");created=1618884473;keyid="test-key-ed25519";alg="ed25519";tag="aloha-directory"';
        $dict = StructuredFields::parseDictionary($header);

        self::assertSame(['dir'], array_keys($dict));
        self::assertSame(['@method', '@authority', '@path', 'content-digest'], array_column($dict['dir']['value'], 'value'));
        self::assertSame(1618884473, $dict['dir']['params']['created']);
        self::assertSame('test-key-ed25519', $dict['dir']['params']['keyid']);

        // Serialisation reproduces the canonical form used in the signature base.
        self::assertSame(substr($header, 4), StructuredFields::serializeInnerList($dict['dir']['value'], $dict['dir']['params']));
    }

    public function testParsesByteSequences(): void
    {
        $bytes = random_bytes(64);
        $dict = StructuredFields::parseDictionary('sig1=:'.base64_encode($bytes).':, other=?1');
        self::assertSame($bytes, $dict['sig1']['value']->bytes);
        self::assertInstanceOf(SfBytes::class, $dict['sig1']['value']);
        self::assertSame(64, \strlen($dict['sig1']['value']->bytes));
        self::assertTrue($dict['other']['value']);
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedInput(string $header): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructuredFields::parseDictionary($header);
    }

    public static function malformed(): iterable
    {
        yield 'unterminated list' => ['a=("@method"'];
        yield 'unterminated string' => ['a="abc'];
        yield 'bad key' => ['A=1'];
        yield 'trailing comma' => ['a=1,'];
        yield 'bad bytes' => ['a=:not base64!:'];
        yield 'control char in string' => ["a=\"x\x01\""];
        yield 'too long' => ['a="'.str_repeat('x', 9000).'"'];
    }
}
