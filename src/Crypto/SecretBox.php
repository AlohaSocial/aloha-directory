<?php

declare(strict_types=1);

namespace App\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated encryption for secrets at rest (TOTP seeds). Uses a dedicated key from
 * DIRECTORY_ENCRYPTION_KEY (base64url, 32 bytes) so that rotating APP_SECRET never locks curators out.
 */
final class SecretBox
{
    private const VERSION = 'v1';

    public function __construct(
        #[Autowire('%env(string:DIRECTORY_ENCRYPTION_KEY)%')]
        private readonly string $encodedKey,
    ) {
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::VERSION.'|'.$context, $nonce, $this->key());

        return self::VERSION.'.'.Base64Url::encode($nonce.$cipher);
    }

    public function decrypt(string $encoded, string $context): string
    {
        [$version, $data] = array_pad(explode('.', $encoded, 2), 2, '');
        if (self::VERSION !== $version) {
            throw new \RuntimeException('Unknown secret format.');
        }
        $raw = Base64Url::decode($data);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (\strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('Secret too short.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLength), self::VERSION.'|'.$context, substr($raw, 0, $nonceLength), $this->key());
        if (false === $plain) {
            throw new \RuntimeException('Secret cannot be decrypted (wrong DIRECTORY_ENCRYPTION_KEY?).');
        }

        return $plain;
    }

    /**
     * Keyed hash for low-entropy-tolerant lookups (recovery codes): useless without the key.
     */
    public function mac(string $data, string $context): string
    {
        return hash_hmac('sha256', self::VERSION.'|'.$context.'|'.$data, $this->key());
    }

    private function key(): string
    {
        try {
            $key = Base64Url::decode(trim($this->encodedKey));
        } catch (\InvalidArgumentException) {
            $key = '';
        }
        if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
            throw new \RuntimeException('DIRECTORY_ENCRYPTION_KEY must be 32 random bytes, base64url-encoded (bin/console app:secrets:generate-encryption-key).');
        }

        return $key;
    }
}
