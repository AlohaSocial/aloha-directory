<?php

declare(strict_types=1);

namespace App\Net;

/**
 * Normalises and validates the domain of an Aloha server: a public DNS name, ASCII (punycode),
 * lowercase, no port, no IP literal, no special-use or internal top-level domain.
 */
final class DomainName
{
    private const RESERVED_TLDS = [
        'localhost', 'local', 'internal', 'intranet', 'test', 'example', 'invalid', 'onion', 'arpa',
        'lan', 'home', 'corp', 'private', 'localdomain', 'alt',
    ];

    public static function normalize(string $input): string
    {
        $domain = trim($input);
        if ('' === $domain || \strlen($domain) > 253 || str_ends_with($domain, '.')) {
            throw new \InvalidArgumentException('Invalid domain.');
        }
        if (1 === preg_match('/[\s\/:@?#\[\]\\\\]/', $domain)) {
            throw new \InvalidArgumentException('A domain must not contain a scheme, port, path or credentials.');
        }
        if (false !== filter_var($domain, \FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('An IP address is not a domain.');
        }

        if (1 === preg_match('/[^\x00-\x7f]/', $domain)) {
            $info = [];
            $ascii = idn_to_ascii($domain, \IDNA_NONTRANSITIONAL_TO_ASCII | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ | \IDNA_USE_STD3_RULES, \INTL_IDNA_VARIANT_UTS46, $info);
            if (false === $ascii || 0 !== ($info['errors'] ?? 0)) {
                throw new \InvalidArgumentException('Invalid internationalised domain.');
            }
            $domain = $ascii;
        }
        $domain = strtolower($domain);

        $labels = explode('.', $domain);
        if (\count($labels) < 2) {
            throw new \InvalidArgumentException('A domain needs at least two labels.');
        }
        foreach ($labels as $label) {
            if (1 !== preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label)) {
                throw new \InvalidArgumentException('Invalid domain label.');
            }
        }
        $tld = end($labels);
        if (1 === preg_match('/\A\d+\z/', $tld) || \in_array($tld, self::RESERVED_TLDS, true)) {
            throw new \InvalidArgumentException('This top-level domain cannot host a public server.');
        }

        return $domain;
    }

    public static function isValid(string $input): bool
    {
        try {
            return self::normalize($input) === $input;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
