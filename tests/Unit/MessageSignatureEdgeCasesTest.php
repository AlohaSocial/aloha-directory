<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Ed25519;
use App\Http\Signature\MessageSignatureVerifier;
use App\Http\Signature\ReplayGuard;
use App\Http\Signature\SfBytes;
use App\Http\Signature\SfToken;
use App\Http\Signature\SignatureException;
use App\Http\Signature\StructuredFields;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hand-built RFC 9421 signatures, so that every parameter and component rule of the directory
 * profile can be exercised (MessageSigner only produces the happy path).
 */
final class MessageSignatureEdgeCasesTest extends TestCase
{
    private const AUTHORITY = 'directory.aloha.social';
    private const PATH = '/api/v1/servers/social.example.org/status';
    private const NOW = 1794571200; // 2026-11-13 12:00:00 UTC

    private MockClock $clock;
    private string $secret;
    private string $public;
    /** @var list<array{string, \DateTimeImmutable}> */
    private array $guardCalls = [];
    private bool $guardAnswer = true;

    protected function setUp(): void
    {
        $this->clock = new MockClock('@'.self::NOW);
        [$this->secret, $this->public] = TestKeys::random();
        $this->guardCalls = [];
        $this->guardAnswer = true;
    }

    public function testHandBuiltBaselineVerifiesAndRecordsTheReplayFingerprint(): void
    {
        [$headers] = $this->signed();
        self::assertSame($this->keyId(), $this->verify('GET', self::PATH, '', $headers));

        self::assertCount(1, $this->guardCalls);
        [$fingerprint, $expires] = $this->guardCalls[0];
        $signature = StructuredFields::parseDictionary($headers['Signature'])['sig']['value'];
        self::assertInstanceOf(SfBytes::class, $signature);
        self::assertSame(hash('sha256', $signature->bytes), $fingerprint);
        self::assertSame(self::NOW + 600, $expires->getTimestamp(), 'kept for the whole window in which the signature is acceptable');
    }

    public function testReplayGuardIsOnlyConsultedForValidSignatures(): void
    {
        [$headers] = $this->signed();
        $headers['Signature'] = 'sig=:'.base64_encode(str_repeat("\1", 64)).':';
        $this->assertRejected('bad_signature', 'GET', self::PATH, '', $headers);
        self::assertSame([], $this->guardCalls, 'an attacker cannot burn fingerprints with forged signatures');
    }

    public function testReplayGuardVerdictIsEnforced(): void
    {
        [$headers] = $this->signed();
        $this->guardAnswer = false;
        $this->assertRejected('replayed_signature', 'GET', self::PATH, '', $headers);
    }

    public function testHostHeaderIsIgnoredForAuthority(): void
    {
        [$headers] = $this->signed();
        $request = $this->request('GET', self::PATH, '', $headers, 'evil.example.net');
        self::assertSame($this->keyId(), $this->verifier()->verify($request, '', $this->resolver()));
    }

    public function testAuthorityIsCompared(): void
    {
        [$headers] = $this->signed(values: ['@authority' => 'DIRECTORY.ALOHA.SOCIAL:443']);
        $this->assertRejected('bad_signature', 'GET', self::PATH, '', $headers);
    }

    public function testTargetUriCanReplaceAuthorityPathAndQuery(): void
    {
        $uri = self::PATH.'?since=5&x=%20y';
        [$headers] = $this->signed(['@method', '@target-uri'], values: ['@target-uri' => 'https://'.self::AUTHORITY.$uri]);
        self::assertSame($this->keyId(), $this->verify('GET', $uri, '', $headers));

        [$headers] = $this->signed(['@method', '@target-uri'], values: ['@target-uri' => 'https://'.self::AUTHORITY.self::PATH.'?since=6&x=%20y']);
        $this->assertRejected('bad_signature', 'GET', $uri, '', $headers);
    }

    public function testQueryIsSignedRawNotDecoded(): void
    {
        $uri = self::PATH.'?a=%2F&b=c';
        [$headers] = $this->signed(['@method', '@authority', '@path', '@query'], values: ['@query' => '?a=%2F&b=c']);
        self::assertSame($this->keyId(), $this->verify('GET', $uri, '', $headers));

        [$headers] = $this->signed(['@method', '@authority', '@path', '@query'], values: ['@query' => '?a=/&b=c']);
        $this->assertRejected('bad_signature', 'GET', $uri, '', $headers);
    }

    public function testAbsoluteFormRequestTargetUsesOnlyThePath(): void
    {
        [$headers] = $this->signed();
        $request = $this->request('GET', 'https://'.self::AUTHORITY.self::PATH, '', $headers);
        self::assertSame($this->keyId(), $this->verifier()->verify($request, '', $this->resolver()));
    }

    public function testPathIsNotNormalised(): void
    {
        [$headers] = $this->signed();
        $this->assertRejected('bad_signature', 'GET', '/api/v1/servers/../servers/social.example.org/status', '', $headers);
        $this->assertRejected('bad_signature', 'GET', '/api/v1/servers/social.example.org/status/', '', $headers);
    }

    public function testCoveredOptionalHeadersAreChecked(): void
    {
        $components = ['@method', '@authority', '@path', 'content-type', 'date'];
        $values = ['content-type' => 'application/json', 'date' => 'Fri, 13 Nov 2026 12:00:00 GMT'];
        [$headers] = $this->signed($components, values: $values);
        $extra = ['Content-Type' => 'application/json', 'Date' => 'Fri, 13 Nov 2026 12:00:00 GMT'];
        self::assertSame($this->keyId(), $this->verify('GET', self::PATH, '', $headers + $extra));

        $this->assertRejected('bad_signature', 'GET', self::PATH, '', $headers + ['Content-Type' => 'text/plain'] + $extra);
        $this->assertRejected('insufficient_coverage', 'GET', self::PATH, '', $headers + ['Content-Type' => 'application/json']);
    }

    public function testSha512ContentDigestIsAccepted(): void
    {
        $body = '{"a":1}';
        $digest = 'sha-512=:'.base64_encode(hash('sha512', $body, true)).':';
        [$headers] = $this->signed(['@method', '@authority', '@path', 'content-digest'], values: ['@method' => 'PUT', 'content-digest' => $digest]);
        self::assertSame($this->keyId(), $this->verify('PUT', self::PATH, $body, $headers + ['Content-Digest' => $digest]));
    }

    #[DataProvider('badDigests')]
    public function testBadContentDigests(string $digest): void
    {
        $body = '{"a":1}';
        [$headers] = $this->signed(['@method', '@authority', '@path', 'content-digest'], values: ['@method' => 'PUT', 'content-digest' => $digest]);
        $this->assertRejected('bad_digest', 'PUT', self::PATH, $body, $headers + ['Content-Digest' => $digest]);
    }

    public static function badDigests(): iterable
    {
        $body = '{"a":1}';
        yield 'only an unsupported algorithm' => ['md5=:'.base64_encode(md5($body, true)).':'];
        yield 'sha-256 of another body' => ['sha-256=:'.base64_encode(hash('sha256', '{"a":2}', true)).':'];
        yield 'good sha-256 but bad sha-512' => ['sha-256=:'.base64_encode(hash('sha256', $body, true)).':, sha-512=:'.base64_encode(hash('sha512', 'x', true)).':'];
        yield 'digest as string' => ['sha-256="'.base64_encode(hash('sha256', $body, true)).'"'];
        yield 'hex digest as token' => ['sha-256='.hash('sha256', $body)];
        yield 'malformed' => ['sha-256=:'.base64_encode(hash('sha256', $body, true))];
    }

    public function testBodyWithoutContentDigestCoverage(): void
    {
        [$headers] = $this->signed();
        $this->assertRejected('insufficient_coverage', 'POST', self::PATH, 'x', $headers);
    }

    /**
     * @param list<string> $components
     */
    #[DataProvider('insufficientCoverage')]
    public function testInsufficientCoverage(array $components, string $uri): void
    {
        [$headers] = $this->signed($components, values: ['@query' => '?a=1', '@target-uri' => 'https://'.self::AUTHORITY.$uri]);
        $this->assertRejected('insufficient_coverage', 'GET', $uri, '', $headers);
    }

    public static function insufficientCoverage(): iterable
    {
        yield 'no method' => [['@authority', '@path'], self::PATH];
        yield 'no authority' => [['@method', '@path'], self::PATH];
        yield 'no path' => [['@method', '@authority'], self::PATH];
        yield 'nothing' => [[], self::PATH];
        yield 'query not covered' => [['@method', '@authority', '@path'], self::PATH.'?a=1'];
        yield 'target-uri without method' => [['@target-uri'], self::PATH];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('badParams')]
    public function testBadParameters(array $params, string $reason): void
    {
        [$headers] = $this->signed(params: $params);
        $this->assertRejected($reason, 'GET', self::PATH, '', $headers);
    }

    public static function badParams(): iterable
    {
        yield 'no created' => [['created' => null], 'malformed_signature'];
        yield 'created as string' => [['created' => (string) self::NOW], 'malformed_signature'];
        yield 'created as decimal' => [['created' => self::NOW + 0.5], 'malformed_signature'];
        yield 'created 301 s ago' => [['created' => self::NOW - 301], 'signature_expired'];
        yield 'created 301 s ahead' => [['created' => self::NOW + 301], 'signature_expired'];
        yield 'expired' => [['expires' => self::NOW - 1], 'signature_expired'];
        yield 'expires as string' => [['expires' => 'never'], 'signature_expired'];
        yield 'no keyid' => [['keyid' => null], 'malformed_signature'];
        yield 'empty keyid' => [['keyid' => ''], 'malformed_signature'];
        yield 'keyid as token' => [['keyid' => new SfToken('abc')], 'malformed_signature'];
        yield 'keyid too long' => [['keyid' => str_repeat('k', 129)], 'malformed_signature'];
        yield 'alg rsa' => [['alg' => 'rsa-pss-sha512'], 'unsupported_algorithm'];
        yield 'alg upper case' => [['alg' => 'ED25519'], 'unsupported_algorithm'];
        yield 'alg as token' => [['alg' => new SfToken('ed25519')], 'unsupported_algorithm'];
        yield 'no tag' => [['tag' => null], 'malformed_signature'];
        yield 'other tag' => [['tag' => 'activitypub'], 'malformed_signature'];
        yield 'nonce as int' => [['nonce' => 5], 'malformed_signature'];
        yield 'nonce too long' => [['nonce' => str_repeat('n', 129)], 'malformed_signature'];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('acceptedParams')]
    public function testAcceptedParameterVariants(array $params): void
    {
        [$headers] = $this->signed(params: $params);
        self::assertSame($this->keyId(), $this->verify('GET', self::PATH, '', $headers));
    }

    public static function acceptedParams(): iterable
    {
        yield 'created 300 s ago' => [['created' => self::NOW - 300]];
        yield 'created 300 s ahead' => [['created' => self::NOW + 300]];
        yield 'no alg' => [['alg' => null]];
        yield 'no nonce' => [['nonce' => null]];
        yield 'expires now' => [['expires' => self::NOW]];
    }

    public function testKeyIdOf128CharactersIsAccepted(): void
    {
        [$headers] = $this->signed(params: ['keyid' => str_repeat('k', 128)]);
        self::assertSame(str_repeat('k', 128), $this->verify('GET', self::PATH, '', $headers));
    }

    public function testSignatureParamsAreCoveredBySignature(): void
    {
        [$headers] = $this->signed();
        // Changing any parameter after signing (here: the nonce) breaks the signature.
        $headers['Signature-Input'] = str_replace('nonce="n0"', 'nonce="n1"', $headers['Signature-Input']);
        $this->assertRejected('bad_signature', 'GET', self::PATH, '', $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('malformedHeaders')]
    public function testMalformedHeaders(array $headers, string $reason): void
    {
        $this->assertRejected($reason, 'GET', self::PATH, '', $headers);
    }

    public static function malformedHeaders(): iterable
    {
        $sig = 'sig=:'.base64_encode(str_repeat("\1", 64)).':';
        $input = 'sig=("@method" "@authority" "@path");created='.self::NOW.';keyid="k";tag="aloha-directory"';
        yield 'only Signature-Input' => [['Signature-Input' => $input], 'missing_signature'];
        yield 'only Signature' => [['Signature' => $sig], 'missing_signature'];
        yield 'unparsable input' => [['Signature-Input' => 'sig=(', 'Signature' => $sig], 'malformed_signature'];
        yield 'unparsable signature' => [['Signature-Input' => $input, 'Signature' => 'sig=:@@:'], 'malformed_signature'];
        yield 'two signatures' => [['Signature-Input' => $input.', sig2=("@method");created=1', 'Signature' => $sig.', sig2=:AA==:'], 'malformed_signature'];
        yield 'labels differ' => [['Signature-Input' => $input, 'Signature' => 'other=:'.base64_encode(str_repeat("\1", 64)).':'], 'malformed_signature'];
        yield 'signature is a string' => [['Signature-Input' => $input, 'Signature' => 'sig="abc"'], 'malformed_signature'];
        yield 'input is not an inner list' => [['Signature-Input' => 'sig="@method";created=1', 'Signature' => $sig], 'malformed_signature'];
        yield 'component is a token' => [['Signature-Input' => 'sig=(method);created=1', 'Signature' => $sig], 'malformed_signature'];
        yield 'component with parameters' => [['Signature-Input' => 'sig=("@query-param";name="a");created=1', 'Signature' => $sig], 'malformed_signature'];
        yield 'unsupported derived component' => [['Signature-Input' => 'sig=("@request-target");created=1', 'Signature' => $sig], 'unsupported_component'];
        yield 'unsupported header' => [['Signature-Input' => 'sig=("@method" "x-forwarded-host");created=1', 'Signature' => $sig], 'unsupported_component'];
        yield 'upper-case header name' => [['Signature-Input' => 'sig=("@method" "Content-Digest");created=1', 'Signature' => $sig], 'unsupported_component'];
        yield 'duplicate component' => [['Signature-Input' => 'sig=("@method" "@path" "@method");created=1', 'Signature' => $sig], 'malformed_signature'];
    }

    public function testShortSignatureIsABadSignatureNotACrash(): void
    {
        [$headers] = $this->signed();
        $headers['Signature'] = 'sig=:'.base64_encode('short').':';
        $this->assertRejected('bad_signature', 'GET', self::PATH, '', $headers);
    }

    public function testResolverReceivesTheKeyIdAndAWrongKeyFails(): void
    {
        [$headers] = $this->signed();
        $asked = [];
        $other = TestKeys::random()[1];
        try {
            $this->verifier()->verify($this->request('GET', self::PATH, '', $headers), '', static function (string $keyId) use (&$asked, $other): string {
                $asked[] = $keyId;

                return $other;
            });
            self::fail('A signature verified with the wrong key.');
        } catch (SignatureException $e) {
            self::assertSame('bad_signature', $e->reason);
        }
        self::assertSame([$this->keyId()], $asked);
    }

    // --- helpers -------------------------------------------------------------------------------

    /**
     * Signs a request by hand. Values default to what the verifier derives for GET self::PATH.
     *
     * @param list<string>          $components
     * @param array<string, mixed>  $params     null removes a default parameter
     * @param array<string, string> $values     component values to sign instead of the defaults
     *
     * @return array{0: array<string, string>}
     */
    private function signed(array $components = ['@method', '@authority', '@path'], array $params = [], array $values = []): array
    {
        $values += ['@method' => 'GET', '@authority' => self::AUTHORITY, '@path' => self::PATH];
        $params = array_filter(array_merge([
            'created' => self::NOW,
            'keyid' => $this->keyId(),
            'alg' => 'ed25519',
            'tag' => 'aloha-directory',
            'nonce' => 'n0',
        ], $params), static fn (mixed $v): bool => null !== $v);
        $items = array_map(static fn (string $c): array => ['value' => $c, 'params' => []], $components);
        $serialized = StructuredFields::serializeInnerList($items, $params);

        $lines = [];
        foreach ($components as $component) {
            $lines[] = '"'.$component.'": '.($values[$component] ?? '');
        }
        $lines[] = '"@signature-params": '.$serialized;

        return [[
            'Signature-Input' => 'sig='.$serialized,
            'Signature' => 'sig=:'.base64_encode(Ed25519::sign(implode("\n", $lines), $this->secret)).':',
        ]];
    }

    private function keyId(): string
    {
        return Ed25519::fingerprint($this->public);
    }

    /**
     * @param array<string, string> $headers
     */
    private function verify(string $method, string $uri, string $body, array $headers): string
    {
        return $this->verifier()->verify($this->request($method, $uri, $body, $headers), $body, $this->resolver());
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertRejected(string $reason, string $method, string $uri, string $body, array $headers): void
    {
        try {
            $this->verify($method, $uri, $body, $headers);
        } catch (SignatureException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());

            return;
        }
        self::fail('The signature was accepted.');
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, string $body, array $headers, string $host = self::AUTHORITY): Request
    {
        $server = ['REQUEST_URI' => $uri, 'HTTP_HOST' => $host];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $path = str_starts_with($uri, 'https://') ? $uri : 'https://'.$host.$uri;

        return Request::create($path, $method, [], [], [], $server, $body);
    }

    private function verifier(): MessageSignatureVerifier
    {
        $guard = new class($this) implements ReplayGuard {
            public function __construct(private readonly MessageSignatureEdgeCasesTest $test)
            {
            }

            public function firstSeen(string $fingerprint, \DateTimeImmutable $expiresAt): bool
            {
                return $this->test->recordGuardCall($fingerprint, $expiresAt);
            }
        };

        return new MessageSignatureVerifier(self::AUTHORITY, $this->clock, $guard);
    }

    /**
     * @internal called by the replay guard stub
     */
    public function recordGuardCall(string $fingerprint, \DateTimeImmutable $expiresAt): bool
    {
        $this->guardCalls[] = [$fingerprint, $expiresAt];

        return $this->guardAnswer;
    }

    private function resolver(): \Closure
    {
        $public = $this->public;
        $keyId = $this->keyId();

        return static fn (string $id): ?string => $id === $keyId || $id === str_repeat('k', 128) ? $public : null;
    }
}
