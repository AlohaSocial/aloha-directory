<?php

declare(strict_types=1);

namespace App\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Configured with LIST_SIGNING_KEYS="kid1=/path/key1,kid2=/path/key2". During a rotation both
 * keys are configured and every list carries both signatures (concept 8.3).
 */
final class KeyFileListSigner implements ListSigner
{
    /** @var array<string, string>|null kid => path */
    private ?array $paths = null;

    public function __construct(
        #[Autowire('%env(string:LIST_SIGNING_KEYS)%')]
        private readonly string $config,
    ) {
    }

    public function sign(string $payload): array
    {
        $signatures = [];
        foreach ($this->paths() as $kid => $path) {
            $seed = KeyFile::readSeed($path);
            [$secret] = Ed25519::keyPairFromSeed($seed);
            sodium_memzero($seed);
            $signatures[] = ['kid' => $kid, 'alg' => 'Ed25519', 'sig' => Base64Url::encode(Ed25519::sign($payload, $secret))];
            sodium_memzero($secret);
        }

        return $signatures;
    }

    public function publicKeys(): array
    {
        $keys = [];
        foreach ($this->paths() as $kid => $path) {
            $seed = KeyFile::readSeed($path);
            [$secret, $public] = Ed25519::keyPairFromSeed($seed);
            sodium_memzero($seed);
            sodium_memzero($secret);
            $keys[$kid] = $public;
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function paths(): array
    {
        if (null !== $this->paths) {
            return $this->paths;
        }
        $paths = [];
        foreach (array_filter(array_map('trim', explode(',', $this->config))) as $item) {
            $parts = explode('=', $item, 2);
            if (2 !== \count($parts) || 1 !== preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $parts[0]) || '' === $parts[1]) {
                throw new \RuntimeException('LIST_SIGNING_KEYS must look like "kid=/path/to/key[,kid2=/path]".');
            }
            $paths[$parts[0]] = $parts[1];
        }
        if ([] === $paths) {
            throw new \RuntimeException('No list signing key configured (LIST_SIGNING_KEYS).');
        }

        return $this->paths = $paths;
    }
}
