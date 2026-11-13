<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\RateLimits;
use PHPUnit\Framework\TestCase;

final class RateLimitsTest extends TestCase
{
    public function testIpv6AddressesCountAsTheirSlash64(): void
    {
        $normalize = new \ReflectionMethod(RateLimits::class, 'normalizeKey');
        self::assertSame('2001:db8:1:2::/64', $normalize->invoke(null, '2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame('2001:db8:1:2::/64', $normalize->invoke(null, '2001:db8:1:2::1'));
        self::assertNotSame($normalize->invoke(null, '2001:db8:1:3::1'), $normalize->invoke(null, '2001:db8:1:2::1'));
        self::assertSame('198.51.100.7', $normalize->invoke(null, '198.51.100.7'));
        self::assertSame("example.org\0key", $normalize->invoke(null, "example.org\0key"));
    }
}
