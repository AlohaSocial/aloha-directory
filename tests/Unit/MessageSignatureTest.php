<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Ed25519;
use App\Http\Signature\MessageSignatureVerifier;
use App\Http\Signature\MessageSigner;
use App\Http\Signature\ReplayGuard;
use App\Http\Signature\SignatureException;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

final class MessageSignatureTest extends TestCase
{
    private const AUTHORITY = 'directory.aloha.social';
    private const PATH = '/api/v1/servers/social.example.org/heartbeat';

    private MockClock $clock;
    private string $secret;
    private string $public;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-13 12:00:00 UTC');
        [$this->secret, $this->public] = TestKeys::random();
    }

    public function testValidSignatureVerifies(): void
    {
        $body = '{"hello":"world"}';
        $request = $this->request('PUT', self::PATH, $body, $this->sign('PUT', self::PATH, $body));
        self::assertSame(Ed25519::fingerprint($this->public), $this->verifier()->verify($request, $body, $this->resolver()));
    }

    public function testSignatureForAnotherAuthorityFails(): void
    {
        $body = '{}';
        $headers = (new MessageSigner($this->secret, $this->public))->sign('PUT', 'evil.example.net', self::PATH, $body, $this->clock->now()->getTimestamp());
        $this->assertRejected('bad_signature', fn () => $this->verifier()->verify($this->request('PUT', self::PATH, $body, $headers), $body, $this->resolver()));
    }

    public function testChangedBodyFailsTheDigest(): void
    {
        $headers = $this->sign('PUT', self::PATH, '{"a":1}');
        $this->assertRejected('bad_digest', fn () => $this->verifier()->verify($this->request('PUT', self::PATH, '{"a":2}', $headers), '{"a":2}', $this->resolver()));
    }

    public function testChangedMethodFails(): void
    {
        $headers = $this->sign('GET', self::PATH, '');
        $this->assertRejected('bad_signature', fn () => $this->verifier()->verify($this->request('DELETE', self::PATH, '', $headers), '', $this->resolver()));
    }

    public function testChangedPathFails(): void
    {
        $headers = $this->sign('GET', '/api/v1/servers/a.example.org/status', '');
        $this->assertRejected('bad_signature', fn () => $this->verifier()->verify($this->request('GET', '/api/v1/servers/b.example.org/status', '', $headers), '', $this->resolver()));
    }

    public function testOldSignatureFails(): void
    {
        $headers = (new MessageSigner($this->secret, $this->public))->sign('GET', self::AUTHORITY, self::PATH, '', $this->clock->now()->getTimestamp() - 301);
        $this->assertRejected('signature_expired', fn () => $this->verifier()->verify($this->request('GET', self::PATH, '', $headers), '', $this->resolver()));
    }

    public function testReplayFails(): void
    {
        $guard = $this->guard();
        $headers = $this->sign('GET', self::PATH, '');
        $this->verifier($guard)->verify($this->request('GET', self::PATH, '', $headers), '', $this->resolver());
        $this->assertRejected('replayed_signature', fn () => $this->verifier($guard)->verify($this->request('GET', self::PATH, '', $headers), '', $this->resolver()));
    }

    public function testUnknownKeyFails(): void
    {
        $headers = $this->sign('GET', self::PATH, '');
        $this->assertRejected('unknown_key', fn () => $this->verifier()->verify($this->request('GET', self::PATH, '', $headers), '', static fn (): ?string => null));
    }

    public function testBodyMustBeCovered(): void
    {
        $headers = $this->sign('PUT', self::PATH, '');
        $this->assertRejected('insufficient_coverage', fn () => $this->verifier()->verify($this->request('PUT', self::PATH, '{"x":1}', $headers), '{"x":1}', $this->resolver()));
    }

    public function testQueryMustBeCovered(): void
    {
        $headers = $this->sign('GET', self::PATH, '');
        $this->assertRejected('insufficient_coverage', fn () => $this->verifier()->verify($this->request('GET', self::PATH.'?x=1', '', $headers), '', $this->resolver()));
    }

    public function testTagIsRequired(): void
    {
        $headers = $this->sign('GET', self::PATH, '');
        $headers['Signature-Input'] = str_replace(';tag="aloha-directory"', '', $headers['Signature-Input']);
        $this->assertRejected('malformed_signature', fn () => $this->verifier()->verify($this->request('GET', self::PATH, '', $headers), '', $this->resolver()));
    }

    public function testUnsignedRequestFails(): void
    {
        $this->assertRejected('missing_signature', fn () => $this->verifier()->verify($this->request('GET', self::PATH, '', []), '', $this->resolver()));
    }

    private function assertRejected(string $reason, callable $verify): void
    {
        try {
            $verify();
        } catch (SignatureException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());

            return;
        }
        self::fail('The signature was accepted.');
    }

    /**
     * @return array<string, string>
     */
    private function sign(string $method, string $path, string $body): array
    {
        return (new MessageSigner($this->secret, $this->public))->sign($method, self::AUTHORITY, $path, $body, $this->clock->now()->getTimestamp());
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, string $body, array $headers): Request
    {
        $server = ['REQUEST_URI' => $uri, 'HTTP_HOST' => self::AUTHORITY];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('https://'.self::AUTHORITY.$uri, $method, [], [], [], $server, $body);
    }

    private function verifier(?ReplayGuard $guard = null): MessageSignatureVerifier
    {
        return new MessageSignatureVerifier(self::AUTHORITY, $this->clock, $guard ?? $this->guard());
    }

    private function guard(): ReplayGuard
    {
        return new class implements ReplayGuard {
            /** @var array<string, true> */
            private array $seen = [];

            public function firstSeen(string $fingerprint, \DateTimeImmutable $expiresAt): bool
            {
                if (isset($this->seen[$fingerprint])) {
                    return false;
                }
                $this->seen[$fingerprint] = true;

                return true;
            }
        };
    }

    private function resolver(): \Closure
    {
        $public = $this->public;

        return static fn (string $keyId): ?string => $keyId === Ed25519::fingerprint($public) ? $public : null;
    }
}
