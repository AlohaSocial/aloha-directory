<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Prove control of a domain for a pending enrolment (concept 6.1), in the background.
 */
final readonly class VerifyEnrolment
{
    public function __construct(
        public string $domain,
        public string $keyId,
    ) {
    }
}
