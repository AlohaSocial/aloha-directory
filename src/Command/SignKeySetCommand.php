<?php

declare(strict_types=1);

namespace App\Command;

use App\Crypto\Ed25519;
use App\Crypto\KeyFile;
use App\Lists\KeySetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The key ceremony step of concept 8.3. Run on the offline machine that holds the root key.
 */
#[AsCommand('app:keys:sign-keyset', 'Signs keys.json with the offline root key')]
final class SignKeySetCommand
{
    /**
     * @param list<string> $key
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Root key file')] ?string $rootKey = null,
        #[Option('Root key id')] ?string $rootKid = null,
        #[Option('Serial; must be higher than the published one')] ?int $serial = null,
        #[Option('List key as kid=base64url-public-key, or kid=base64url-public-key:revoked; repeatable')] array $key = [],
        #[Option('Output file')] string $out = 'keys.json',
        #[Option('Validity in days')] int $validityDays = 365,
    ): int {
        if (null === $rootKey || null === $rootKid || null === $serial || $serial < 1) {
            $io->error('--root-key, --root-kid and a positive --serial are required.');

            return Command::INVALID;
        }
        $keys = [];
        foreach ($key as $item) {
            if (1 !== preg_match('/\A([A-Za-z0-9._-]{1,64})=([A-Za-z0-9_-]{43})(?::(active|revoked))?\z/', $item, $m)) {
                $io->error('Invalid --key: '.$item);

                return Command::INVALID;
            }
            $keys[] = ['kid' => $m[1], 'public_key' => $m[2], 'status' => $m[3] ?? 'active'];
        }
        if ([] === $keys) {
            $io->error('At least one --key is required.');

            return Command::INVALID;
        }
        $seed = KeyFile::readSeed($rootKey);
        [$secret] = Ed25519::keyPairFromSeed($seed);
        sodium_memzero($seed);
        $json = KeySetService::sign($keys, $serial, $rootKid, $secret, new \DateTimeImmutable(), $validityDays);
        sodium_memzero($secret);

        if (false === @file_put_contents($out, $json, \LOCK_EX)) {
            $io->error('Cannot write '.$out);

            return Command::FAILURE;
        }
        $io->success(\sprintf('Key set serial %d written to %s. Install it on the directory with app:keys:install.', $serial, $out));

        return Command::SUCCESS;
    }
}
