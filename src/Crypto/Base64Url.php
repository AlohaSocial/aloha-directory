<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Strict base64url (RFC 4648 section 5) without padding.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * @throws \InvalidArgumentException when the input is not canonical base64url
     */
    public static function decode(string $encoded): string
    {
        if ('' === $encoded) {
            return '';
        }
        if (1 !== preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded)) {
            throw new \InvalidArgumentException('Invalid base64url.');
        }
        try {
            $bytes = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new \InvalidArgumentException('Invalid base64url.');
        }
        // Reject non-canonical encodings (stray bits in the last character).
        if (self::encode($bytes) !== $encoded) {
            throw new \InvalidArgumentException('Non-canonical base64url.');
        }

        return $bytes;
    }
}
