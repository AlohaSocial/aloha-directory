<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Base64Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Base64UrlTest extends TestCase
{
    public function testRoundTrip(): void
    {
        foreach (['', 'f', 'fo', 'foo', random_bytes(33), "\xff\xfe\xfd"] as $bytes) {
            self::assertSame($bytes, Base64Url::decode(Base64Url::encode($bytes)));
        }
        self::assertSame('_-8', Base64Url::encode("\xff\xef"));
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Base64Url::decode($input);
    }

    public static function invalid(): iterable
    {
        yield 'padding' => ['Zm8='];
        yield 'standard alphabet' => ['ab+/'];
        yield 'whitespace' => ['Zm 8'];
        yield 'non-canonical trailing bits' => ['Zm9'];
    }
}
