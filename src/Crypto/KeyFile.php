<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Ed25519 seed files: one line with the base64url-encoded 32-byte seed.
 */
final class KeyFile
{
    public static function readSeed(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(\sprintf('Key file "%s" is missing or unreadable.', $path));
        }
        $perms = fileperms($path);
        if (false !== $perms && 0 !== ($perms & 0o007)) {
            throw new \RuntimeException(\sprintf('Key file "%s" must not be world-accessible (chmod 600 or 640).', $path));
        }
        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Cannot read key file "%s".', $path));
        }
        try {
            $seed = Base64Url::decode(trim($raw));
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException(\sprintf('Key file "%s" is not a base64url Ed25519 seed.', $path));
        } finally {
            sodium_memzero($raw);
        }
        if (Ed25519::SEED_BYTES !== \strlen($seed)) {
            throw new \RuntimeException(\sprintf('Key file "%s" does not contain a 32-byte seed.', $path));
        }

        return $seed;
    }

    /**
     * Writes a new random seed with mode 0600 and returns the public key. Refuses to overwrite.
     */
    public static function generate(string $path): string
    {
        if (file_exists($path)) {
            throw new \RuntimeException(\sprintf('Refusing to overwrite existing key file "%s".', $path));
        }
        $seed = random_bytes(Ed25519::SEED_BYTES);
        [$secret, $public] = Ed25519::keyPairFromSeed($seed);
        sodium_memzero($secret);

        $old = umask(0o077);
        try {
            $handle = fopen($path, 'x');
            if (false === $handle) {
                throw new \RuntimeException(\sprintf('Cannot create key file "%s".', $path));
            }
            fwrite($handle, Base64Url::encode($seed)."\n");
            fclose($handle);
            chmod($path, 0o600);
        } finally {
            umask($old);
            sodium_memzero($seed);
        }

        return $public;
    }
}
