<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Net\FetchException;
use App\Net\SafeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SafeHttpClientEdgeCasesTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testRequestOptionsAreHardened(): void
    {
        $this->client([new MockResponse('ok')])->get('https://example.org/x', 100, ['Accept' => 'application/json', 'User-Agent' => 'spoofed'], true, 3.0);

        $request = $this->requests[0];
        self::assertSame('GET', $request['method']);
        $headers = implode("\n", $request['options']['headers'] ?? []);
        self::assertStringContainsString('User-Agent: AlohaTest/1.0', $headers, 'the configured user agent wins');
        self::assertStringNotContainsString('spoofed', $headers);
        self::assertStringContainsString('Accept-Encoding: identity', $headers, 'no compressed bodies (decompression bombs)');
        self::assertStringContainsString('Accept: application/json', $headers);
        self::assertSame(0, $request['options']['max_redirects']);
        self::assertTrue($request['options']['verify_peer']);
        self::assertTrue($request['options']['verify_host']);
        self::assertSame(3.0, $request['options']['timeout']);
        self::assertSame(6.0, $request['options']['max_duration']);
    }

    public function testErrorStatusesAreReturnedNotThrown(): void
    {
        foreach ([404, 410, 500, 503] as $status) {
            $result = $this->client([new MockResponse('body', ['http_code' => $status])])->get('https://example.org/', 100);
            self::assertSame($status, $result->status);
            self::assertSame('body', $result->body);
        }
    }

    public function testRedirectWithoutLocationIsReturned(): void
    {
        $result = $this->client([new MockResponse('', ['http_code' => 304])])->get('https://example.org/', 100);
        self::assertSame(304, $result->status);
        self::assertCount(1, $this->requests);
    }

    #[DataProvider('locations')]
    public function testRelativeLocationsAreResolved(string $from, string $location, string $expected): void
    {
        $result = $this->client([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => $location]]),
            new MockResponse('ok'),
        ])->get($from, 100, [], false);

        self::assertSame($expected, $result->url);
        self::assertSame($expected, $this->requests[1]['url']);
    }

    public static function locations(): iterable
    {
        yield 'absolute path' => ['https://example.org/a/b', '/c', 'https://example.org/c'];
        yield 'relative path' => ['https://example.org/a/b', 'c', 'https://example.org/a/c'];
        yield 'relative from root' => ['https://example.org', 'c', 'https://example.org/c'];
        yield 'protocol relative' => ['https://example.org/a', '//cdn.example.net/x', 'https://cdn.example.net/x'];
        yield 'absolute' => ['https://example.org/a', 'https://other.example.net/y', 'https://other.example.net/y'];
    }

    public function testRedirectLimit(): void
    {
        $responses = array_fill(0, 4, new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => '/again']]));
        $responses[] = new MockResponse('finally');

        try {
            $this->client($responses)->get('https://example.org/', 100);
            self::fail('Followed more than three redirects.');
        } catch (FetchException $e) {
            self::assertStringContainsString('Too many redirects', $e->getMessage());
        }
        self::assertCount(4, $this->requests, 'the original request plus three redirects');

        $this->setUp();
        $three = array_fill(0, 3, new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => '/again']]));
        $three[] = new MockResponse('finally');
        self::assertSame('finally', $this->client($three)->get('https://example.org/', 100)->body);
    }

    #[DataProvider('unsafeRedirects')]
    public function testUnsafeRedirectTargetsAreRefusedEvenWhenCrossHostIsAllowed(string $location): void
    {
        $this->expectException(FetchException::class);
        try {
            $this->client([
                new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => $location]]),
                new MockResponse('secret'),
            ])->get('https://example.org/', 100, [], false);
        } finally {
            self::assertCount(1, $this->requests, 'the unsafe target is never requested');
        }
    }

    public static function unsafeRedirects(): iterable
    {
        yield 'loopback ip' => ['https://127.0.0.1/admin'];
        yield 'metadata ip' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'ipv6' => ['https://[fd00::1]/'];
        yield 'localhost' => ['https://localhost/'];
        yield 'internal tld' => ['https://db.internal/'];
        yield 'other port' => ['https://example.org:22/'];
        yield 'credentials' => ['https://a:b@example.org/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://example.org/'];
        yield 'protocol relative to ip' => ['//10.0.0.1/'];
    }

    public function testExplicitDefaultPortIsAllowed(): void
    {
        self::assertSame('example.org', $this->client([])->assertSafeUrl('https://Example.ORG:443/x'));
    }

    public function testAnnouncedLengthAboveTheCapIsRefusedBeforeReading(): void
    {
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('announces more than 100 bytes');
        $this->client([new MockResponse(str_repeat('x', 101), ['response_headers' => ['Content-Length' => '101']])])->get('https://example.org/', 100);
    }

    public function testBodyExactlyAtTheCapIsAccepted(): void
    {
        self::assertSame(100, \strlen($this->client([new MockResponse(str_repeat('x', 100))])->get('https://example.org/', 100)->body));
    }

    public function testStreamedChunksAreCapped(): void
    {
        $chunks = (static function (): \Generator {
            for ($i = 0; $i < 10; ++$i) {
                yield str_repeat('x', 30);
            }
        })();
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('returned more than 100 bytes');
        $this->client([new MockResponse($chunks)])->get('https://example.org/', 100);
    }

    public function testTransportErrorsBecomeFetchExceptions(): void
    {
        try {
            $this->client([new MockResponse('', ['error' => 'SSL certificate problem: self-signed certificate'])])->get('https://example.org/', 100);
            self::fail('A transport error was swallowed.');
        } catch (FetchException $e) {
            self::assertStringContainsString('could not be fetched', $e->getMessage());
            self::assertStringContainsString('self-signed', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testDeadlineLimitsTheTimeouts(): void
    {
        $this->client([new MockResponse('ok')])->get('https://example.org/', 100, [], true, 5.0, microtime(true) + 2.0);
        self::assertLessThanOrEqual(2.0, $this->requests[0]['options']['timeout']);
        self::assertGreaterThan(1.5, $this->requests[0]['options']['timeout']);
        self::assertLessThanOrEqual(2.0, $this->requests[0]['options']['max_duration']);
    }

    public function testExhaustedDeadlineStopsRedirectChains(): void
    {
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('time budget');
        $this->client([new MockResponse('ok')])->get('https://example.org/', 100, [], true, 5.0, microtime(true) + 0.2);
    }

    public function testJsonDepthIsBounded(): void
    {
        $deep = str_repeat('[', 20).str_repeat(']', 20);
        $result = $this->client([new MockResponse($deep)])->get('https://example.org/', 1000);
        $this->expectException(FetchException::class);
        $result->json(16);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $result = $this->client([new MockResponse('ok', ['response_headers' => ['Strict-Transport-Security' => 'max-age=1']])])->get('https://example.org/', 100);
        self::assertSame('max-age=1', $result->header('STRICT-transport-security'));
        self::assertNull($result->header('x-missing'));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): SafeHttpClient
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('unexpected request', ['http_code' => 599]);
        });

        return new SafeHttpClient($mock, 'AlohaTest/1.0');
    }
}
