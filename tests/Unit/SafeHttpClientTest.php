<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Net\FetchException;
use App\Net\SafeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SafeHttpClientTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function testRefusesUnsafeUrls(string $url): void
    {
        $client = new SafeHttpClient(new MockHttpClient(new MockResponse('nope')), 'test');
        $this->expectException(FetchException::class);
        $client->get($url, 1000);
    }

    public static function unsafeUrls(): iterable
    {
        yield 'plain http' => ['http://example.org/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'ip literal' => ['https://127.0.0.1/'];
        yield 'ipv6 literal' => ['https://[::1]/'];
        yield 'port' => ['https://example.org:8080/'];
        yield 'credentials' => ['https://user:pass@example.org/'];
        yield 'localhost' => ['https://localhost/'];
        yield 'internal' => ['https://metadata.internal/'];
    }

    public function testCapsBodySize(): void
    {
        $client = new SafeHttpClient(new MockHttpClient(new MockResponse(str_repeat('x', 5000))), 'test');
        $this->expectException(FetchException::class);
        $client->get('https://example.org/', 1000);
    }

    public function testRefusesCrossHostRedirectWhenRestricted(): void
    {
        $client = new SafeHttpClient(new MockHttpClient([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://other.example.net/x']])]), 'test');
        $this->expectException(FetchException::class);
        $client->get('https://example.org/.well-known/nodeinfo', 1000);
    }

    public function testFollowsSameHostRedirect(): void
    {
        $client = new SafeHttpClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => '/index.php/.well-known/nodeinfo']]),
            new MockResponse('{"ok":true}'),
        ]), 'test');
        $result = $client->get('https://example.org/.well-known/nodeinfo', 1000);
        self::assertSame('https://example.org/index.php/.well-known/nodeinfo', $result->url);
        self::assertSame(['ok' => true], $result->json());
    }

    public function testRedirectToPlainHttpIsRefused(): void
    {
        $client = new SafeHttpClient(new MockHttpClient([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://example.org/x']])]), 'test');
        $this->expectException(FetchException::class);
        $client->get('https://example.org/', 1000, [], false);
    }

    public function testPrivateNetworkWrapperBlocksResolvedPrivateAddresses(): void
    {
        $mock = new MockHttpClient(new MockResponse('secret', ['primary_ip' => '10.0.0.5']));
        $client = new SafeHttpClient(new NoPrivateNetworkHttpClient($mock), 'test');
        $this->expectException(FetchException::class);
        $client->get('https://example.org/', 1000);
    }
}
