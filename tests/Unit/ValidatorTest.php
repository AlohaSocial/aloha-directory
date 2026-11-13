<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\Fixtures;
use App\Validation\HeartbeatValidator;
use App\Validation\InputReader;
use App\Validation\ProfileValidator;
use App\Validation\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidProfileIsNormalised(): void
    {
        $input = Fixtures::profile();
        $input['audience']['languages'] = ['DE', 'en-us', 'de'];
        $input['jurisdiction']['hosting_country'] = 'de';
        $input['identity']['name'] = "  Example\u{202E} Social  ";
        $input['unknown_group'] = ['x' => 1];

        $profile = (new ProfileValidator())->validate($input);

        self::assertSame(['de', 'en-US'], $profile['audience']['languages']);
        self::assertSame('DE', $profile['jurisdiction']['hosting_country']);
        self::assertSame('Example Social', $profile['identity']['name'], 'bidi overrides and whitespace are stripped');
        self::assertArrayNotHasKey('unknown_group', $profile);
    }

    public function testProfileErrorsAreCollected(): void
    {
        $input = Fixtures::profile();
        $input['terms']['terms_url'] = 'http://insecure.example.org/terms';
        $input['identity']['description'] = str_repeat('x', 281);
        $input['jurisdiction']['hosting_country'] = 'XX';
        $input['operator']['type'] = 'pirate';
        $input['moderation']['abuse_email'] = 'not-an-email';
        $input['search'] = ['capabilities' => ['accounts']];

        try {
            (new ProfileValidator())->validate($input);
            self::fail('Expected validation errors.');
        } catch (ValidationFailed $e) {
            foreach (['terms.terms_url', 'identity.description', 'jurisdiction.hosting_country', 'operator.type', 'moderation.abuse_email', 'search.index_policy_url', 'search.log_retention_days', 'search.rate_limit_per_minute'] as $path) {
                self::assertArrayHasKey($path, $e->errors, $path);
            }
        }
    }

    public function testHeartbeat(): void
    {
        $hb = (new HeartbeatValidator())->validate(Fixtures::heartbeat('social.example.org', new \DateTimeImmutable('2026-11-13T12:00:00Z')));
        self::assertSame(412, $hb['signup']['available_accounts']);
        self::assertInstanceOf(\DateTimeImmutable::class, $hb['sent_at']);

        $bad = Fixtures::heartbeat('social.example.org', new \DateTimeImmutable());
        $bad['signup']['available_accounts'] = -1;
        $bad['software']['version'] = 'latest';
        $bad['health']['federation_ok'] = 'yes';
        try {
            (new HeartbeatValidator())->validate($bad);
            self::fail('Expected validation errors.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('signup.available_accounts', $e->errors);
            self::assertArrayHasKey('software.version', $e->errors);
            self::assertArrayHasKey('health.federation_ok', $e->errors);
        }
    }

    public function testCleanText(): void
    {
        self::assertSame("a\nb", InputReader::cleanText("a\r\n\x00b", true));
        self::assertSame('a b', InputReader::cleanText("a\n\t b", false));
        self::assertSame('ab', InputReader::cleanText("a\u{2066}b\u{FEFF}", false));
    }
}
