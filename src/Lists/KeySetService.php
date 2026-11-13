<?php

declare(strict_types=1);

namespace App\Lists;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Crypto\ListVerifier;
use App\Crypto\SignedEnvelope;
use App\Entity\ListBuild;
use App\Enum\ListName;
use App\Repository\ListBuildRepository;
use App\Service\AuditLogger;
use App\Service\DirectorySettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * keys.json (concept 8.3): the list keys, signed by the offline root key.
 *
 * The key set is created on an offline machine with `app:keys:sign-keyset` and brought to the
 * directory with `app:keys:install`, which verifies it against the pinned root key(s) first.
 */
final class KeySetService
{
    public function __construct(
        #[Autowire('%env(string:ROOT_PUBLIC_KEYS)%')]
        private readonly string $rootKeysConfig,
        private readonly DirectorySettings $settings,
        private readonly EntityManagerInterface $em,
        private readonly ListBuildRepository $builds,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{kid: string, public_key: string, status: string, not_after?: string}> $keys
     */
    public static function sign(array $keys, int $serial, string $rootKid, string $rootSecretKey, \DateTimeImmutable $now, int $validityDays): string
    {
        foreach ($keys as $key) {
            Ed25519::decodePublicKey($key['public_key']);
            if (!\in_array($key['status'], ['active', 'revoked'], true)) {
                throw new \InvalidArgumentException('Key status must be active or revoked.');
            }
        }
        $payload = [
            'list' => ListName::Keys->value,
            'version' => 1,
            'serial' => $serial,
            'issued_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->modify("+{$validityDays} days")->format('Y-m-d\TH:i:s\Z'),
            'keys' => array_map(static fn (array $k): array => ['alg' => 'Ed25519', ...$k], $keys),
        ];

        return SignedEnvelope::build($payload, static fn (string $p): array => [[
            'kid' => $rootKid, 'alg' => 'Ed25519', 'sig' => Base64Url::encode(Ed25519::sign($p, $rootSecretKey)),
        ]])['json'];
    }

    /**
     * @return array<string, string> kid => raw public key
     */
    public function rootKeys(): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $this->rootKeysConfig))) as $item) {
            [$kid, $encoded] = array_pad(explode('=', $item, 2), 2, '');
            $keys[$kid] = Ed25519::decodePublicKey($encoded);
        }

        return $keys;
    }

    /**
     * @return array{serial: int, listKeys: array<string, string>}
     */
    public function install(string $json): array
    {
        $current = $this->builds->latest(ListName::Keys->value);
        $verified = (new ListVerifier($this->rootKeys()))->verifyKeySet($json, null !== $current ? (int) $current->serial : null, $this->clock->now());
        $payload = $verified['payload'];

        $path = $this->settings->outputDir.'/lists/v1/'.ListName::Keys->fileName();
        AtomicFileWriter::write($path, $json);

        $now = $this->clock->now();
        $this->em->persist(new ListBuild(
            ListName::Keys->value, (string) $payload['serial'], $now, new \DateTimeImmutable((string) $payload['expires_at']),
            hash('sha256', $json), hash('sha256', Base64Url::decode((string) json_decode($json, true)['payload'])),
            \count((array) $payload['keys']), Base64Url::decode((string) json_decode($json, true)['payload']), $json,
            array_keys($this->rootKeys()), 'install',
        ));
        $this->audit->system('keys.installed', null, ['serial' => $payload['serial'], 'active_list_keys' => array_keys($verified['listKeys'])]);
        $this->em->flush();

        return ['serial' => (int) $payload['serial'], 'listKeys' => $verified['listKeys']];
    }

    /**
     * The verified installed key set, or null when none is installed or it does not verify.
     *
     * @return array{payload: array<string, mixed>, listKeys: array<string, string>}|null
     */
    public function installed(): ?array
    {
        $path = $this->settings->outputDir.'/lists/v1/'.ListName::Keys->fileName();
        if (!is_file($path)) {
            return null;
        }
        try {
            return (new ListVerifier($this->rootKeys()))->verifyKeySet((string) file_get_contents($path), null, $this->clock->now());
        } catch (\Throwable) {
            return null;
        }
    }
}
