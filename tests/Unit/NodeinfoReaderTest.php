<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Check\NodeinfoReader;
use App\Net\FetchException;
use App\Net\SafeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NodeinfoReaderTest extends TestCase
{
    private const DOMAIN = 'social.example.org';

    /** @var array<string, MockResponse|\Closure(): MockResponse> */
    private array $routes = [];
    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->routes = [];
        $this->requested = [];
    }

    public function testReadsTheLinkedDocument(): void
    {
        $this->index([['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => 'https://social.example.org/nodeinfo/2.1']]);
        $this->document('https://social.example.org/nodeinfo/2.1', ['software' => ['name' => 'Aloha', 'version' => '0.27.1'], 'openRegistrations' => false]);

        $info = $this->reader()->read(self::DOMAIN);
        self::assertSame('aloha', $info->softwareName, 'lowercased');
        self::assertSame('0.27.1', $info->version);
        self::assertFalse($info->openRegistrations);
        self::assertSame(['https://social.example.org/.well-known/nodeinfo', 'https://social.example.org/nodeinfo/2.1'], $this->requested);
    }

    public function testPrefersTheNewestSchemaWhateverTheOrder(): void
    {
        $this->index([
            ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/1.0', 'href' => 'https://social.example.org/n/1.0'],
            ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => 'https://social.example.org/n/2.0'],
            'garbage',
            ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.2', 'href' => 42],
            ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.2', 'href' => 'https://social.example.org/n/2.2'],
            ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => 'https://social.example.org/n/2.1'],
        ]);
        $this->document('https://social.example.org/n/2.2', ['software' => ['name' => 'aloha', 'version' => '1.0.0']]);

        $info = $this->reader()->read(self::DOMAIN);
        self::assertSame('1.0.0', $info->version);
        self::assertNull($info->openRegistrations, 'absent');
        self::assertSame('https://social.example.org/n/2.2', $this->requested[1]);
    }

    public function testLongVersionsAreTruncated(): void
    {
        $this->index([['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => 'https://social.example.org/n']]);
        $this->document('https://social.example.org/n', ['software' => ['name' => 'aloha', 'version' => str_repeat('ü', 100)], 'openRegistrations' => 'yes']);

        $info = $this->reader()->read(self::DOMAIN);
        self::assertSame(str_repeat('ü', 64), $info->version, 'cut at 64 characters, not bytes');
        self::assertNull($info->openRegistrations, 'non-boolean is unknown');
    }

    #[DataProvider('foreignHrefs')]
    public function testTheDocumentMustBeOnTheServersOwnHost(string $href): void
    {
        $this->index([['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => $href]]);
        $this->document($href, ['software' => ['name' => 'aloha', 'version' => '0.27.0']]);

        try {
            $this->reader()->read(self::DOMAIN);
            self::fail('A foreign nodeinfo document was accepted.');
        } catch (FetchException) {
            self::assertCount(1, $this->requested, 'the foreign document is never fetched');
        }
    }

    public static function foreignHrefs(): iterable
    {
        yield 'other host' => ['https://aloha.example.net/nodeinfo/2.1'];
        yield 'subdomain' => ['https://evil.social.example.org/nodeinfo/2.1'];
        yield 'suffix trick' => ['https://social.example.org.evil.example/nodeinfo/2.1'];
        yield 'relative' => ['/nodeinfo/2.1'];
        yield 'userinfo trick' => ['https://social.example.org@evil.example/nodeinfo/2.1'];
    }

    public function testInsecureOrOddUrlsOnTheSameHostAreRefusedBySafeHttpClient(): void
    {
        foreach (['http://social.example.org/nodeinfo', 'https://social.example.org:8443/nodeinfo'] as $href) {
            $this->setUp();
            $this->index([['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => $href]]);
            try {
                $this->reader()->read(self::DOMAIN);
                self::fail($href.' was fetched.');
            } catch (FetchException) {
                self::assertCount(1, $this->requested);
            }
        }
    }

    public function testCrossHostRedirectOfTheIndexIsRefused(): void
    {
        $this->routes['https://social.example.org/.well-known/nodeinfo'] = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://aloha.example.net/.well-known/nodeinfo']]);
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('redirects to another host');
        $this->reader()->read(self::DOMAIN);
    }

    /**
     * @param array<string, MockResponse> $responses
     */
    #[DataProvider('failures')]
    public function testFailures(array $responses, string $message): void
    {
        $this->routes = $responses;
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage($message);
        $this->reader()->read(self::DOMAIN);
    }

    public static function failures(): iterable
    {
        $index = 'https://social.example.org/.well-known/nodeinfo';
        $doc = 'https://social.example.org/nodeinfo/2.1';
        $links = (string) json_encode(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => $doc]]]);

        yield 'index 404' => [[$index => new MockResponse('', ['http_code' => 404])], 'answered HTTP 404'];
        yield 'index 500' => [[$index => new MockResponse('', ['http_code' => 500])], 'answered HTTP 500'];
        yield 'index not json' => [[$index => new MockResponse('<html>')], 'did not return valid JSON'];
        yield 'index scalar json' => [[$index => new MockResponse('"links"')], 'did not return a JSON object'];
        yield 'index without links' => [[$index => new MockResponse('{"x":1}')], 'has no links'];
        yield 'links is a string' => [[$index => new MockResponse('{"links":"https://x"}')], 'has no links'];
        yield 'only nodeinfo 1.0' => [[$index => new MockResponse((string) json_encode(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/1.0', 'href' => $doc]]]))], 'No nodeinfo 2.x document'];
        yield 'rel with https scheme' => [[$index => new MockResponse((string) json_encode(['links' => [['rel' => 'https://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => $doc]]]))], 'No nodeinfo 2.x document'];
        yield 'index too large' => [[$index => new MockResponse(str_repeat(' ', 65_537))], 'more than 65536 bytes'];
        yield 'document 403' => [[$index => new MockResponse($links), $doc => new MockResponse('', ['http_code' => 403])], 'document answered HTTP 403'];
        yield 'document without software' => [[$index => new MockResponse($links), $doc => new MockResponse('{"version":"2.1"}')], 'no software name and version'];
        yield 'numeric version' => [[$index => new MockResponse($links), $doc => new MockResponse('{"software":{"name":"aloha","version":27}}')], 'no software name and version'];
        yield 'document too large' => [[$index => new MockResponse($links), $doc => new MockResponse(str_repeat(' ', 262_145))], 'more than 262144 bytes'];
        yield 'connection error' => [[$index => new MockResponse('', ['error' => 'Connection refused'])], 'could not be fetched'];
    }

    public function testExpiredDeadlineStopsBeforeAnyRequest(): void
    {
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('time budget');
        try {
            $this->reader()->read(self::DOMAIN, microtime(true) + 0.1);
        } finally {
            self::assertSame([], $this->requested);
        }
    }

    /**
     * @param list<mixed> $links
     */
    private function index(array $links): void
    {
        $this->routes['https://social.example.org/.well-known/nodeinfo'] = new MockResponse((string) json_encode(['links' => $links]));
    }

    /**
     * @param array<string, mixed> $document
     */
    private function document(string $url, array $document): void
    {
        $this->routes[$url] = new MockResponse((string) json_encode($document));
    }

    private function reader(): NodeinfoReader
    {
        $mock = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requested[] = $url;

            return $this->routes[$url] ?? new MockResponse('not found', ['http_code' => 404]);
        });

        return new NodeinfoReader(new SafeHttpClient($mock, 'test'));
    }
}
