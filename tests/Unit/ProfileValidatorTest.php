<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\Fixtures;
use App\Validation\ProfileValidator;
use App\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileValidatorTest extends TestCase
{
    public function testFixtureIsValidAndShapeIsStable(): void
    {
        $profile = (new ProfileValidator())->validate(Fixtures::profile());

        self::assertSame(['identity', 'terms', 'audience', 'jurisdiction', 'operator', 'moderation', 'account', 'search', 'etag'], array_keys($profile));
        self::assertSame(['dm', 'shorts', 'video'], $profile['account']['features'], 'sorted');
        self::assertSame(['accounts', 'hashtags'], $profile['search']['capabilities']);
        self::assertNull($profile['identity']['banner_url']);
        self::assertSame("We are a small association.\nEveryone is welcome.", $profile['identity']['long_description'], 'multiline keeps newlines');
        self::assertEqualsCanonicalizing(
            array_merge(ProfileValidator::REVIEWED_GROUPS, ProfileValidator::LIVE_GROUPS),
            array_merge(array_diff(array_keys($profile), ['etag']), ['atproto']),
            'every group is either reviewed or live',
        );
    }

    public function testOptionalGroupsMayBeOmitted(): void
    {
        $input = Fixtures::profile();
        unset($input['search'], $input['account']['features'], $input['etag'], $input['terms']['min_age'], $input['operator']['commitment_until']);
        $profile = (new ProfileValidator())->validate($input);
        self::assertSame(['capabilities' => [], 'index_policy_url' => null, 'log_retention_days' => null, 'rate_limit_per_minute' => null], $profile['search']);
        self::assertSame([], $profile['account']['features']);
        self::assertNull($profile['etag']);
        self::assertNull($profile['terms']['min_age']);
        self::assertNull($profile['operator']['commitment_until']);
    }

    #[DataProvider('languages')]
    public function testLanguageTags(array $tags, ?array $expected): void
    {
        $input = Fixtures::profile();
        $input['audience']['languages'] = $tags;
        if (null === $expected) {
            self::assertArrayHasKey('audience.languages', $this->errors($input));

            return;
        }
        self::assertSame($expected, (new ProfileValidator())->validate($input)['audience']['languages']);
    }

    public static function languages(): iterable
    {
        yield 'canonical case' => [['EN-us', 'zh-hant-tw', 'SR-latn'], ['en-US', 'zh-Hant-TW', 'sr-Latn']];
        yield 'three-letter and UN M.49 region' => [['gsw', 'es-419'], ['gsw', 'es-419']];
        yield 'duplicates after canonicalisation' => [['de', 'DE', 'de'], ['de']];
        yield 'underscore' => [['en_US'], null];
        yield 'too long language' => [['engl'], null];
        yield 'private use' => [['x-klingon'], null];
        yield 'extension' => [['de-DE-u-co-phonebk'], null];
        yield 'empty list' => [[], null];
        yield 'eleven languages' => [array_fill(0, 11, 'de'), null];
        yield 'not a list' => [['a' => 'de'], null];
        yield 'injection' => [['de"><script>'], null];
    }

    #[DataProvider('topics')]
    public function testTopic(string $topic, ?string $expected): void
    {
        $input = Fixtures::profile();
        $input['audience']['topic'] = $topic;
        if (null === $expected) {
            self::assertArrayHasKey('audience.topic', $this->errors($input), $topic);

            return;
        }
        self::assertSame($expected, (new ProfileValidator())->validate($input)['audience']['topic']);
    }

    public static function topics(): iterable
    {
        yield 'general' => ['general', 'general'];
        yield 'lowercased' => ['Open Source', 'open source'];
        yield 'hyphen' => ['e-bikes', 'e-bikes'];
        yield 'umlaut' => ['Fußball', 'fußball'];
        yield 'cjk' => ['音楽', '音楽'];
        yield 'digits' => ['3d printing', '3d printing'];
        yield 'emoji' => ['cats 🐈', null];
        yield 'punctuation' => ['cats!', null];
        yield 'url' => ['https://evil.example', null];
        yield 'double space collapsed' => ['open   source', 'open source'];
        yield 'leading hyphen' => ['-cats', null];
        yield 'double hyphen' => ['cats--dogs', null];
        yield 'too long' => [str_repeat('a', 41), null];
    }

    #[DataProvider('countries')]
    public function testCountry(mixed $value, ?string $expected): void
    {
        $input = Fixtures::profile();
        $input['jurisdiction']['operator_seat'] = $value;
        if (null === $expected) {
            self::assertArrayHasKey('jurisdiction.operator_seat', $this->errors($input));

            return;
        }
        self::assertSame($expected, (new ProfileValidator())->validate($input)['jurisdiction']['operator_seat']);
    }

    public static function countries(): iterable
    {
        yield 'upper' => ['AT', 'AT'];
        yield 'lower' => ['ch', 'CH'];
        yield 'unknown code' => ['XX', null];
        yield 'EU is not a country' => ['EU', null];
        yield 'alpha-3' => ['DEU', null];
        yield 'one letter' => ['D', null];
        yield 'empty' => ['', null];
        yield 'number' => [49, null];
    }

    #[DataProvider('yearMonths')]
    public function testCommitmentUntil(string $value, bool $valid): void
    {
        $input = Fixtures::profile();
        $input['operator']['commitment_until'] = $value;
        if ($valid) {
            self::assertSame($value, (new ProfileValidator())->validate($input)['operator']['commitment_until']);
        } else {
            self::assertArrayHasKey('operator.commitment_until', $this->errors($input), $value);
        }
    }

    public static function yearMonths(): iterable
    {
        yield 'valid' => ['2028-12', true];
        yield 'january' => ['2030-01', true];
        yield 'month 13' => ['2028-13', false];
        yield 'month 00' => ['2028-00', false];
        yield 'last century' => ['1999-12', false];
        yield 'with day' => ['2028-12-01', false];
        yield 'slash' => ['2028/12', false];
        yield 'single digit month' => ['2028-1', false];
    }

    /**
     * @param array<string, mixed> $patch dotted path => value
     */
    #[DataProvider('invalidFields')]
    public function testInvalidFields(array $patch, string $path): void
    {
        $input = Fixtures::profile();
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
        yield 'name missing' => [['identity.name' => null], 'identity.name'];
        yield 'name too long' => [['identity.name' => str_repeat('n', 101)], 'identity.name'];
        yield 'long description too long' => [['identity.long_description' => str_repeat('x', 5001)], 'identity.long_description'];
        yield 'logo over http' => [['identity.logo_url' => 'http://social.example.org/logo.png'], 'identity.logo_url'];
        yield 'banner with credentials' => [['identity.banner_url' => 'https://u:p@social.example.org/b.png'], 'identity.banner_url'];
        yield 'privacy url missing' => [['terms.privacy_url' => null], 'terms.privacy_url'];
        yield 'no rules' => [['terms.rules' => []], 'terms.rules'];
        yield '51 rules' => [['terms.rules' => array_fill(0, 51, 'Be kind.')], 'terms.rules'];
        yield 'rule too long' => [['terms.rules' => ['ok', str_repeat('r', 1001)]], 'terms.rules.1'];
        yield 'min age 12' => [['terms.min_age' => 12], 'terms.min_age'];
        yield 'min age 22' => [['terms.min_age' => 22], 'terms.min_age'];
        yield 'audience too long' => [['audience.audience' => str_repeat('a', 281)], 'audience.audience'];
        yield 'gdpr as string' => [['jurisdiction.gdpr' => 'yes'], 'jurisdiction.gdpr'];
        yield 'dpa over http' => [['jurisdiction.dpa_url' => 'http://example.org/dpa'], 'jurisdiction.dpa_url'];
        yield 'operator type' => [['operator.type' => 'Company'], 'operator.type'];
        yield 'funding missing' => [['operator.funding' => ''], 'operator.funding'];
        yield 'shutdown notice too short' => [['operator.shutdown_notice_months' => 2], 'operator.shutdown_notice_months'];
        yield 'shutdown notice too long' => [['operator.shutdown_notice_months' => 61], 'operator.shutdown_notice_months'];
        yield 'no moderators' => [['moderation.moderators' => 0], 'moderation.moderators'];
        yield 'abuse email invalid' => [['moderation.abuse_email' => 'abuse at example.org'], 'moderation.abuse_email'];
        yield 'abuse actor javascript' => [['moderation.abuse_actor' => 'javascript:alert(1)'], 'moderation.abuse_actor'];
        yield 'response within 0 hours' => [['moderation.response_hours' => 0], 'moderation.response_hours'];
        yield 'response after 15 days' => [['moderation.response_hours' => 337], 'moderation.response_hours'];
        yield 'policy url missing' => [['moderation.policy_url' => null], 'moderation.policy_url'];
        yield 'signup mode invite' => [['account.signup_mode' => 'invite'], 'account.signup_mode'];
        yield 'email required missing' => [['account.email_required' => null], 'account.email_required'];
        yield 'negative storage' => [['account.storage_mb' => -1], 'account.storage_mb'];
        yield 'unknown feature' => [['account.features' => ['dm', 'crypto']], 'account.features'];
        yield 'unknown capability' => [['search.capabilities' => ['accounts', 'everything']], 'search.capabilities'];
        yield 'log retention 8 days' => [['search.log_retention_days' => 8], 'search.log_retention_days'];
        yield 'rate limit 0' => [['search.rate_limit_per_minute' => 0], 'search.rate_limit_per_minute'];
        yield 'etag too long' => [['etag' => str_repeat('e', 129)], 'etag'];
    }

    public function testSearchProviderMustStateItsPolicies(): void
    {
        $input = Fixtures::profile();
        $input['search'] = ['capabilities' => ['statuses']];
        self::assertSame(
            ['search.index_policy_url', 'search.log_retention_days', 'search.rate_limit_per_minute'],
            array_keys($this->errors($input)),
        );

        // An invalid value is reported once, not twice.
        $input['search'] = ['capabilities' => ['statuses'], 'index_policy_url' => 'http://x.example.org', 'log_retention_days' => 0, 'rate_limit_per_minute' => 10];
        self::assertSame(['search.index_policy_url' => ['must be an absolute https:// URL']], $this->errors($input));

        // Without capabilities nothing more is required.
        $input['search'] = ['capabilities' => []];
        self::assertSame([], (new ProfileValidator())->validate($input)['search']['capabilities']);
    }

    public function testAtprotoGroup(): void
    {
        $input = Fixtures::profile();
        $input['atproto'] = ['enabled' => true, 'handle_example' => 'alice.social.example.org'];
        self::assertSame(['enabled' => true, 'handle_example' => 'alice.social.example.org', 'move_away' => false], (new ProfileValidator())->validate($input)['atproto']);

        $input['atproto'] = ['enabled' => 'yes', 'move_away' => 1];
        self::assertSame(['atproto.enabled', 'atproto.move_away'], array_keys($this->errors($input)));
    }

    public function testGroupsOfTheWrongTypeAreRequiredFieldErrors(): void
    {
        $input = Fixtures::profile();
        $input['operator'] = 'Example e.V.';
        $errors = $this->errors($input);
        self::assertSame(['is required'], $errors['operator.name']);
        self::assertArrayHasKey('operator.type', $errors);
        self::assertArrayNotHasKey('identity.name', $errors);
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, list<string>>
     */
    private function errors(array $input): array
    {
        try {
            (new ProfileValidator())->validate($input);
        } catch (ValidationFailed $e) {
            return $e->errors;
        }
        self::fail('The profile was accepted.');
    }
}
