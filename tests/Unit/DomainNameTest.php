<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Net\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainNameTest extends TestCase
{
    public function testNormalises(): void
    {
        self::assertSame('social.example.org', DomainName::normalize(' Social.Example.ORG '));
        self::assertSame('xn--mnchen-3ya.de', DomainName::normalize('münchen.de'));
        self::assertTrue(DomainName::isValid('a-b.example.org'));
        self::assertFalse(DomainName::isValid('Social.example.org'), 'isValid requires the normalised form');
    }

    #[DataProvider('invalid')]
    public function testRejects(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DomainName::normalize($input);
    }

    public static function invalid(): iterable
    {
        yield 'ipv4' => ['127.0.0.1'];
        yield 'ipv6' => ['::1'];
        yield 'port' => ['example.org:8443'];
        yield 'scheme' => ['https://example.org'];
        yield 'path' => ['example.org/x'];
        yield 'credentials' => ['user@example.org'];
        yield 'single label' => ['localhost'];
        yield 'localhost tld' => ['app.localhost'];
        yield 'internal tld' => ['nextcloud.internal'];
        yield 'local tld' => ['printer.local'];
        yield 'onion' => ['abc.onion'];
        yield 'numeric tld' => ['10.0.0.300'];
        yield 'trailing dot' => ['example.org.'];
        yield 'leading hyphen' => ['-a.example.org'];
        yield 'underscore' => ['a_b.example.org'];
        yield 'empty label' => ['a..example.org'];
        yield 'too long' => [implode('.', array_fill(0, 5, str_repeat('a', 60))).'.org'];
        yield 'label too long' => [str_repeat('a', 64).'.org'];
    }
}
