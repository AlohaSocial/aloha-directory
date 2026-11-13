<?php

declare(strict_types=1);

namespace App\Net;

final class SystemDnsResolver implements DnsResolver
{
    public function canReceiveMail(string $domain): bool
    {
        try {
            $domain = DomainName::normalize($domain);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return checkdnsrr($domain.'.', 'MX') || checkdnsrr($domain.'.', 'A') || checkdnsrr($domain.'.', 'AAAA');
    }
}
