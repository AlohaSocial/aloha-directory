<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Crypto\Ed25519;
use App\Http\Signature\MessageSigner;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An Aloha server as the directory sees it: its key, its signed requests, and the documents it serves.
 */
final class FakeAlohaServer
{
    public string $secret;
    public string $public;
    public ?string $challenge = null;
    public string $softwareName = 'aloha';
    public string $version = '0.27.0';
    public bool $registrationsOpen = true;
    public bool $approvalRequired = true;
    public bool $tlsOk = true;
    /** @var array<string, int> */
    public array $statusOverrides = [];

    public function __construct(public readonly string $domain)
    {
        [$this->secret, $this->public] = TestKeys::random();
    }

    public function keyId(): string
    {
        return Ed25519::fingerprint($this->public);
    }

    public function publicKey(): string
    {
        return TestKeys::encode($this->public);
    }

    public function rotateKey(): void
    {
        [$this->secret, $this->public] = TestKeys::random();
    }

    /**
     * @return array<string, string> server variables for the Symfony test client
     */
    public function signedServerVars(string $method, string $path, string $body, int $now, string $authority): array
    {
        $vars = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ((new MessageSigner($this->secret, $this->public))->sign($method, $authority, $path, $body, $now) as $name => $value) {
            $vars['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $vars;
    }

    public function respond(string $method, string $url): ?MockResponse
    {
        $parts = parse_url($url);
        if (($parts['host'] ?? '') !== $this->domain) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if (!$this->tlsOk) {
            return new MockResponse('', ['error' => 'SSL certificate problem: certificate has expired']);
        }
        if (isset($this->statusOverrides[$path])) {
            return new MockResponse('', ['http_code' => $this->statusOverrides[$path]]);
        }

        return match ($path) {
            '/.well-known/aloha-directory' => new MockResponse((string) json_encode(['challenge' => $this->challenge, 'key_id' => $this->keyId()])),
            '/.well-known/nodeinfo' => new MockResponse((string) json_encode(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => "https://{$this->domain}/nodeinfo/2.1"]]])),
            '/nodeinfo/2.1' => new MockResponse((string) json_encode(['version' => '2.1', 'software' => ['name' => $this->softwareName, 'version' => $this->version], 'openRegistrations' => $this->registrationsOpen])),
            '/status.php' => new MockResponse('{"installed":true,"maintenance":false,"version":"33.0.2"}', ['response_headers' => ['Strict-Transport-Security' => 'max-age=31536000']]),
            '/api/v2/instance' => new MockResponse((string) json_encode(['domain' => $this->domain, 'registrations' => ['enabled' => $this->registrationsOpen, 'approval_required' => $this->approvalRequired]])),
            '/terms', '/privacy', '/rules', '/index-policy' => new MockResponse('<html><body>Policy</body></html>'),
            '/logo.png' => new MockResponse(self::png()),
            default => new MockResponse('Not found', ['http_code' => 404]),
        };
    }

    public static function png(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 140));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
