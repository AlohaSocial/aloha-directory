<?php

declare(strict_types=1);

namespace App\Net;

interface DnsResolver
{
    /** True when the domain can receive mail (an MX record, or an A/AAAA record as fallback). */
    public function canReceiveMail(string $domain): bool;
}
