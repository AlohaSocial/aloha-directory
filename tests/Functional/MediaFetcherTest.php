<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Server;
use App\Media\MediaFetcher;
use App\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MediaFetcherTest extends FunctionalTestCase
{
    /** @var array<string, \Closure(): MockResponse> */
    private array $responses = [];
    /** @var list<string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->responses = [];
        $this->fetched = [];
        $http = static::getContainer()->get('app.outbound_http_client');
        \assert($http instanceof MockHttpClient);
        $http->setResponseFactory(function (string $method, string $url): MockResponse {
            $this->fetched[] = $url;

            return isset($this->responses[$url]) ? ($this->responses[$url])() : new MockResponse('', ['http_code' => 404]);
        });
    }

    public function testImagesAreReencodedAsContentAddressedWebp(): void
    {
        $this->serve('https://cdn.example.net/logo.png', self::png(64, 64));
        $this->serve('https://cdn.example.net/banner.jpg', self::jpeg(3000, 1000));
        $server = $this->newServer(['logo' => 'https://cdn.example.net/logo.png', 'banner' => 'https://cdn.example.net/banner.jpg', 'avatar' => 'https://cdn.example.net/ignored.png']);

        $this->fetcher()->processServer($server);
        $server = $this->server('media.example.org');

        self::assertNull($server->mediaPending);
        self::assertNull($server->mediaError);
        self::assertNotContains('https://cdn.example.net/ignored.png', $this->fetched, 'unknown kinds are not fetched');
        foreach (['logo' => [64, 64], 'banner' => [1500, 500]] as $kind => [$w, $h]) {
            $hash = 'logo' === $kind ? $server->logoHash : $server->bannerHash;
            self::assertNotNull($hash);
            $file = $this->mediaDir().'/'.$hash.'.webp';
            self::assertFileExists($file);
            $bytes = (string) file_get_contents($file);
            self::assertSame($hash, hash('sha256', $bytes), 'the name is the hash of the content');
            self::assertSame('RIFF', substr($bytes, 0, 4));
            self::assertSame('WEBP', substr($bytes, 8, 4));
            $info = getimagesizefromstring($bytes);
            self::assertNotFalse($info);
            self::assertSame([$w, $h, \IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]], $kind.': scaled to fit, never enlarged');
        }
    }

    public function testMetadataAndTrailingPayloadsAreStripped(): void
    {
        $png = self::png(32, 32);
        // A tEXt chunk after IHDR and a polyglot payload after IEND.
        $text = 'Comment'."\0".'secret-gps-location';
        $chunk = pack('N', \strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
        $tainted = substr($png, 0, 33).$chunk.substr($png, 33).'<?php system($_GET["c"]); ?>';
        self::assertNotFalse(@imagecreatefromstring($tainted), 'the tainted file is still a decodable PNG');

        $this->serve('https://cdn.example.net/logo.png', $tainted);
        $this->fetcher()->processServer($this->newServer(['logo' => 'https://cdn.example.net/logo.png']));

        $server = $this->server('media.example.org');
        self::assertNull($server->mediaError);
        $bytes = (string) file_get_contents($this->mediaDir().'/'.$server->logoHash.'.webp');
        self::assertStringNotContainsString('secret-gps-location', $bytes);
        self::assertStringNotContainsString('<?php', $bytes);
    }

    public function testSameImageIsStoredOnce(): void
    {
        $this->serve('https://a.example.net/l.png', self::png(40, 40));
        $this->serve('https://b.example.net/l.png', self::png(40, 40));
        $this->fetcher()->processServer($this->newServer(['logo' => 'https://a.example.net/l.png'], 'one.example.org'));
        $this->fetcher()->processServer($this->newServer(['logo' => 'https://b.example.net/l.png'], 'two.example.org'));

        self::assertSame($this->server('one.example.org')->logoHash, $this->server('two.example.org')->logoHash);
        self::assertCount(1, glob($this->mediaDir().'/*.webp') ?: []);
    }

    #[DataProvider('rejected')]
    public function testRejectedImages(\Closure $body, string $error): void
    {
        $this->responses['https://cdn.example.net/logo'] = $body;
        $this->fetcher()->processServer($this->newServer(['logo' => 'https://cdn.example.net/logo']));

        $server = $this->server('media.example.org');
        self::assertNull($server->logoHash);
        self::assertNull($server->mediaPending, 'a failure is final until the profile changes');
        self::assertSame('logo: '.$error, $server->mediaError);
        self::assertSame([], glob($this->mediaDir().'/*.webp') ?: []);
    }

    public static function rejected(): iterable
    {
        yield '404' => [static fn (): MockResponse => new MockResponse('', ['http_code' => 404]), 'The image answered HTTP 404.'];
        yield 'html' => [static fn (): MockResponse => new MockResponse('<html><body>hi</body></html>'), 'The file is not a PNG, JPEG, WebP or GIF image.'];
        yield 'svg' => [static fn (): MockResponse => new MockResponse('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>'), 'The file is not a PNG, JPEG, WebP or GIF image.'];
        yield 'bmp' => [static fn (): MockResponse => new MockResponse(self::bmp(64, 64)), 'The file is not a PNG, JPEG, WebP or GIF image.'];
        yield 'too small' => [static fn (): MockResponse => new MockResponse(self::png(15, 64)), 'The image dimensions are out of range.'];
        yield 'decompression bomb' => [static fn (): MockResponse => new MockResponse(self::pngHeader(5000, 5000)), 'The image dimensions are out of range.'];
        yield 'corrupt data' => [static fn (): MockResponse => new MockResponse(self::pngHeader(64, 64)), 'The image cannot be decoded.'];
        yield 'too large' => [static fn (): MockResponse => new MockResponse(str_repeat("\0", 5_000_001)), 'https://cdn.example.net/logo returned more than 5000000 bytes.'];
        yield 'connection error' => [static fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused']), 'https://cdn.example.net/logo could not be fetched: Connection refused'];
    }

    public function testUnsafeUrlsAreNeverFetched(): void
    {
        $this->fetcher()->processServer($this->newServer(['logo' => 'http://cdn.example.net/logo.png', 'banner' => 'https://169.254.169.254/latest/meta-data/']));

        self::assertSame([], $this->fetched);
        $error = (string) $this->server('media.example.org')->mediaError;
        self::assertStringContainsString('logo: Only https:// URLs are fetched.', $error);
        self::assertStringContainsString('banner: The URL host is not a public domain name.', $error);
    }

    public function testOneFailureDoesNotDiscardTheOtherImage(): void
    {
        $this->serve('https://cdn.example.net/logo.png', self::png(64, 64));
        $this->fetcher()->processServer($this->newServer(['logo' => 'https://cdn.example.net/logo.png', 'banner' => 'https://cdn.example.net/missing.png']));

        $server = $this->server('media.example.org');
        self::assertNotNull($server->logoHash);
        self::assertNull($server->bannerHash);
        self::assertSame('banner: The image answered HTTP 404.', $server->mediaError);
    }

    public function testStaleResultsAreNotApplied(): void
    {
        $server = $this->newServer(['logo' => 'https://cdn.example.net/old.png']);
        $id = $server->id;
        // While the old image downloads, the server sends a profile with a new logo.
        $this->responses['https://cdn.example.net/old.png'] = function () use ($id): MockResponse {
            $this->em->getConnection()->update('server', ['media_pending' => '{"logo":"https://cdn.example.net/new.png"}'], ['id' => $id]);

            return new MockResponse(self::png(64, 64));
        };

        $this->fetcher()->processServer($server);
        $server = $this->server('media.example.org');
        self::assertNull($server->logoHash);
        self::assertSame(['logo' => 'https://cdn.example.net/new.png'], $server->mediaPending, 'left for the next run');
    }

    public function testProcessPendingHonoursTheLimit(): void
    {
        $this->serve('https://cdn.example.net/l.png', self::png(64, 64));
        foreach (['a', 'b', 'c'] as $name) {
            $this->newServer(['logo' => 'https://cdn.example.net/l.png'], $name.'.example.org');
        }
        $this->newServer(null, 'none.example.org');

        self::assertSame(2, $this->fetcher()->processPending(2));
        self::assertSame(1, $this->fetcher()->processPending(20));
        self::assertSame(0, $this->fetcher()->processPending(20));
    }

    public function testGarbageCollectionKeepsReferencedAndRecentFiles(): void
    {
        $dir = $this->mediaDir();
        mkdir($dir, 0o755, true);
        $referenced = str_repeat('a', 64);
        $oldOrphan = str_repeat('b', 64);
        $newOrphan = str_repeat('c', 64);
        foreach ([$referenced, $oldOrphan, $newOrphan] as $hash) {
            file_put_contents("{$dir}/{$hash}.webp", 'x');
        }
        file_put_contents("{$dir}/notes.txt", 'x');
        touch("{$dir}/{$referenced}.webp", time() - 200 * 86400);
        touch("{$dir}/{$oldOrphan}.webp", time() - 91 * 86400);
        touch("{$dir}/{$newOrphan}.webp", time() - 89 * 86400);
        touch("{$dir}/notes.txt", time() - 200 * 86400);
        $server = $this->newServer(null);
        $server->bannerHash = $referenced;
        $this->em->flush();

        self::assertSame(1, $this->fetcher()->collectGarbage());
        self::assertFileExists("{$dir}/{$referenced}.webp");
        self::assertFileDoesNotExist("{$dir}/{$oldOrphan}.webp");
        self::assertFileExists("{$dir}/{$newOrphan}.webp");
        self::assertFileExists("{$dir}/notes.txt", 'only .webp files are collected');
        self::assertSame(1, $this->fetcher()->collectGarbage(30));
        self::assertFileDoesNotExist("{$dir}/{$newOrphan}.webp");
    }

    // --- helpers -------------------------------------------------------------------------------

    /**
     * @param array<string, string>|null $pending
     */
    private function newServer(?array $pending, string $domain = 'media.example.org'): Server
    {
        $server = new Server($domain, $this->clock->now());
        $server->mediaPending = $pending;
        $this->em->persist($server);
        $this->em->flush();

        return $server;
    }

    private function serve(string $url, string $body): void
    {
        $this->responses[$url] = static fn (): MockResponse => new MockResponse($body);
    }

    private function fetcher(): MediaFetcher
    {
        return static::getContainer()->get(MediaFetcher::class);
    }

    private function mediaDir(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/media';
    }

    private static function png(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 90));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function jpeg(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 90, 200));
        ob_start();
        imagejpeg($image, null, 50);

        return (string) ob_get_clean();
    }

    private static function bmp(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        ob_start();
        imagebmp($image);

        return (string) ob_get_clean();
    }

    /**
     * A PNG signature and IHDR chunk announcing the given size, followed by a broken IDAT.
     */
    private static function pngHeader(int $w, int $h): string
    {
        $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
        $idat = 'garbage-not-zlib';

        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr))
            .pack('N', \strlen($idat)).'IDAT'.$idat.pack('N', crc32('IDAT'.$idat))
            .pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }
}
