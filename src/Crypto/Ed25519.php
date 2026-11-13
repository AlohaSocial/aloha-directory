<?php

declare(strict_types=1);

namespace App\Crypto;

final class Ed25519
{
    public const PUBLIC_KEY_BYTES = SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    public const SEED_BYTES = SODIUM_CRYPTO_SIGN_SEEDBYTES;
    public const SIGNATURE_BYTES = SODIUM_CRYPTO_SIGN_BYTES;

    /**
     * Never throws; any malformed input is simply "not valid".
     */
    public static function verify(string $signature, string $message, string $publicKey): bool
    {
        if (self::SIGNATURE_BYTES !== \strlen($signature) || self::PUBLIC_KEY_BYTES !== \strlen($publicKey)) {
            return false;
        }
        if (self::isWeakPublicKey($publicKey)) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Decodes and validates a base64url public key. Rejects the all-zero key and other small-order points.
     */
    public static function decodePublicKey(string $encoded): string
    {
        $key = Base64Url::decode($encoded);
        if (self::PUBLIC_KEY_BYTES !== \strlen($key) || self::isWeakPublicKey($key)) {
            throw new \InvalidArgumentException('Not a valid Ed25519 public key.');
        }

        return $key;
    }

    /**
     * Key identifier used in HTTP message signatures: base64url(SHA-256(raw public key)).
     */
    public static function fingerprint(string $publicKey): string
    {
        return Base64Url::encode(hash('sha256', $publicKey, true));
    }

    /**
     * @return array{0: string, 1: string} [secretKey(64), publicKey(32)]
     */
    public static function keyPairFromSeed(string $seed): array
    {
        if (self::SEED_BYTES !== \strlen($seed)) {
            throw new \InvalidArgumentException('Ed25519 seed must be 32 bytes.');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        sodium_memzero($pair);

        return [$secret, $public];
    }

    public static function sign(string $message, string $secretKey): string
    {
        if (SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== \strlen($secretKey)) {
            throw new \InvalidArgumentException('Ed25519 secret keys are 64 bytes.');
        }

        return sodium_crypto_sign_detached($message, $secretKey);
    }

    private static function isWeakPublicKey(string $key): bool
    {
        // libsodium rejects small-order points during verification, but an explicit check
        // lets us refuse such keys at enrolment time with a clear error.
        if (self::PUBLIC_KEY_BYTES !== \strlen($key) || str_repeat("\0", 32) === $key) {
            return true;
        }
        try {
            sodium_crypto_sign_ed25519_pk_to_curve25519($key);
        } catch (\SodiumException) {
            return true;
        }

        return false;
    }
}
