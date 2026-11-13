<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Net\DnsResolver;

final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, bool> */
    public array $mailDomains = [];

    public function canReceiveMail(string $domain): bool
    {
        return $this->mailDomains[$domain] ?? true;
    }
}
