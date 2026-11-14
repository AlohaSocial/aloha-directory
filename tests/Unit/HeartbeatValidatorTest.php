<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\Fixtures;
use App\Validation\HeartbeatValidator;
use App\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeartbeatValidatorTest extends TestCase
{
    public function testMinimalHeartbeatGetsDefaults(): void
    {
        $hb = (new HeartbeatValidator())->validate([
            'domain' => 'social.example.org',
            'software' => ['name' => 'aloha', 'version' => '0.27.0'],
            'signup' => ['open' => false, 'mode' => 'closed', 'available_accounts' => 0, 'approval_backlog' => 0],
            'health' => ['federation_ok' => true],
            'sent_at' => '2026-11-13T12:00:00Z',
        ]);

        self::assertNull($hb['software']['nextcloud']);
        self::assertSame(['users_total' => null, 'users_active_month' => null], $hb['usage']);
        self::assertSame(['accounts' => false, 'hashtags' => false, 'statuses' => false, 'trends' => false, 'load' => null, 'providers_used' => null], $hb['search']);
        self::assertNull($hb['health']['queue_lag_seconds']);
        self::assertNull($hb['profile_etag']);
        self::assertArrayNotHasKey('atproto', $hb);
        self::assertEquals(new \DateTimeImmutable('2026-11-13T12:00:00Z'), $hb['sent_at']);
    }

    public function testUnknownFieldsAreDropped(): void
    {
        $input = $this->valid();
        $input['evil'] = '<script>';
        $input['signup']['extra'] = 1;
        $hb = (new HeartbeatValidator())->validate($input);
        self::assertArrayNotHasKey('evil', $hb);
        self::assertArrayNotHasKey('extra', $hb['signup']);
    }

    public function testEmptyInputReportsEveryRequiredField(): void
    {
        $errors = $this->errors([]);
        self::assertEqualsCanonicalizing([
            'sent_at', 'software.version', 'domain', 'software.name', 'signup.open', 'signup.mode',
            'signup.available_accounts', 'signup.approval_backlog', 'health.federation_ok',
        ], array_keys($errors));
    }

    #[DataProvider('timestamps')]
    public function testSentAtFormats(string $value, ?string $expected): void
    {
        $input = $this->valid();
        $input['sent_at'] = $value;
        if (null === $expected) {
            self::assertSame(['must be an RFC 3339 timestamp'], $this->errors($input)['sent_at'] ?? null, $value);

            return;
        }
        $hb = (new HeartbeatValidator())->validate($input);
        self::assertSame($expected, $hb['sent_at']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'));
    }

    public static function timestamps(): iterable
    {
        yield 'Z' => ['2026-11-13T12:00:00Z', '2026-11-13T12:00:00'];
        yield 'offset' => ['2026-11-13T14:00:00+02:00', '2026-11-13T12:00:00'];
        yield 'negative offset' => ['2026-11-13T07:00:00-05:00', '2026-11-13T12:00:00'];
        yield 'milliseconds' => ['2026-11-13T12:00:00.123Z', '2026-11-13T12:00:00'];
        yield 'space separator' => ['2026-11-13 12:00:00Z', null];
        yield 'no zone' => ['2026-11-13T12:00:00', null];
        yield 'date only' => ['2026-11-13', null];
        yield 'unix time' => ['1794571200', null];
        yield 'relative' => ['now', null];
        yield 'garbage' => ['yesterday at noon', null];
    }

    #[DataProvider('impossibleDates')]
    public function testImpossibleDatesAreRejected(string $value): void
    {
        $input = $this->valid();
        $input['sent_at'] = $value;
        self::assertArrayHasKey('sent_at', $this->errors($input));
    }

    public static function impossibleDates(): iterable
    {
        yield 'February 31' => ['2026-02-31T12:00:00Z'];
        yield 'hour 25' => ['2026-11-13T25:00:00Z'];
        yield 'month 13' => ['2026-13-01T12:00:00Z'];
    }

    #[DataProvider('versions')]
    public function testSoftwareVersion(string $version, bool $valid): void
    {
        $input = $this->valid();
        $input['software']['version'] = $version;
        if ($valid) {
            self::assertSame($version, (new HeartbeatValidator())->validate($input)['software']['version']);
        } else {
            self::assertSame(['must be a semantic version'], $this->errors($input)['software.version'] ?? null, $version);
        }
    }

    public static function versions(): iterable
    {
        yield 'plain' => ['0.27.0', true];
        yield 'big numbers' => ['10.200.3000', true];
        yield 'pre-release' => ['0.28.0-beta.1', true];
        yield 'build metadata' => ['0.28.0+git.abc123', true];
        yield 'two parts' => ['0.27', false];
        yield 'four parts' => ['0.27.0.1', false];
        yield 'v prefix' => ['v0.27.0', false];
        yield 'latest' => ['latest', false];
        yield 'trailing newline' => ["0.27.0\n-", false];
        yield 'pre-release too long' => ['0.27.0-'.str_repeat('a', 21), false];
        yield 'pre-release with space' => ['0.27.0-beta 1', false];
        yield 'injection' => ['0.27.0-<b>', false];
    }

    /**
     * @param array<string, mixed> $patch dotted path => value
     */
    #[DataProvider('invalidFields')]
    public function testInvalidFields(array $patch, string $path): void
    {
        $input = $this->valid();
        foreach ($patch as $dotted => $value) {
            $ref = &$input;
            foreach (explode('.', $dotted) as $segment) {
                $ref = &$ref[$segment];
            }
            $ref = $value;
            unset($ref);
        }
        $errors = $this->errors($input);
        self::assertSame([$path], array_keys($errors), (string) json_encode($errors));
    }

    public static function invalidFields(): iterable
    {
        yield 'domain too long' => [['domain' => str_repeat('a', 254)], 'domain'];
        yield 'domain not a string' => [['domain' => ['x']], 'domain'];
        yield 'software name too long' => [['software.name' => str_repeat('a', 65)], 'software.name'];
        yield 'nextcloud too long' => [['software.nextcloud' => str_repeat('1', 33)], 'software.nextcloud'];
        yield 'signup open as string' => [['signup.open' => 'true'], 'signup.open'];
        yield 'unknown signup mode' => [['signup.mode' => 'lottery'], 'signup.mode'];
        yield 'negative accounts' => [['signup.available_accounts' => -1], 'signup.available_accounts'];
        yield 'too many accounts' => [['signup.available_accounts' => 100_000_001], 'signup.available_accounts'];
        yield 'accounts as float' => [['signup.available_accounts' => 5.0], 'signup.available_accounts'];
        yield 'negative backlog' => [['signup.approval_backlog' => -3], 'signup.approval_backlog'];
        yield 'users as string' => [['usage.users_total' => '1588'], 'usage.users_total'];
        yield 'search flag as int' => [['search.accounts' => 1], 'search.accounts'];
        yield 'load above 1' => [['search.load' => 1.5], 'search.load'];
        yield 'negative load' => [['search.load' => -0.1], 'search.load'];
        yield 'providers above 1000' => [['search.providers_used' => 1001], 'search.providers_used'];
        yield 'queue lag above a year' => [['health.queue_lag_seconds' => 365 * 86400 + 1], 'health.queue_lag_seconds'];
        yield 'federation missing' => [['health.federation_ok' => null], 'health.federation_ok'];
        yield 'etag too long' => [['profile_etag' => str_repeat('e', 129)], 'profile_etag'];
        yield 'sent_at too long' => [['sent_at' => str_repeat('1', 41)], 'sent_at'];
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $input = $this->valid();
        $input['signup']['available_accounts'] = 100_000_000;
        $input['search']['load'] = 1;
        $input['search']['providers_used'] = 0;
        $input['health']['queue_lag_seconds'] = 365 * 86400;
        $hb = (new HeartbeatValidator())->validate($input);
        self::assertSame(100_000_000, $hb['signup']['available_accounts']);
        self::assertSame(1.0, $hb['search']['load']);
    }

    public function testAtprotoBlock(): void
    {
        $input = $this->valid();
        $input['atproto'] = ['enabled' => true, 'relay_last_crawl' => '2026-11-13T11:00:00+00:00'];
        self::assertSame(['enabled' => true, 'handles_ok' => false, 'relay_last_crawl' => '2026-11-13T11:00:00+00:00'], (new HeartbeatValidator())->validate($input)['atproto']);

        $input['atproto'] = ['handles_ok' => 'yes', 'relay_last_crawl' => 'last tuesday'];
        self::assertEqualsCanonicalizing(['atproto.enabled', 'atproto.handles_ok', 'atproto.relay_last_crawl'], array_keys($this->errors($input)));

        $input['atproto'] = [];
        self::assertSame(['atproto.enabled'], array_keys($this->errors($input)), 'an empty block still needs "enabled"');
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return Fixtures::heartbeat('social.example.org', new \DateTimeImmutable('2026-11-13T12:00:00Z'));
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, list<string>>
     */
    private function errors(array $input): array
    {
        try {
            (new HeartbeatValidator())->validate($input);
        } catch (ValidationFailed $e) {
            self::assertSame('Validation failed.', $e->getMessage());

            return $e->errors;
        }
        self::fail('The heartbeat was accepted.');
    }
}
