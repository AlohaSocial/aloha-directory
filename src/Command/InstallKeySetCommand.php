<?php

declare(strict_types=1);

namespace App\Command;

use App\Crypto\ListSigner;
use App\Lists\KeySetService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:keys:install', 'Verifies a signed keys.json against the pinned root keys and publishes it')]
final class InstallKeySetCommand
{
    public function __construct(
        private readonly KeySetService $keys,
        private readonly ListSigner $signer,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Signed keys.json from app:keys:sign-keyset')] string $file): int
    {
        $json = @file_get_contents($file);
        if (false === $json) {
            $io->error('Cannot read '.$file);

            return Command::FAILURE;
        }
        $result = $this->keys->install($json);
        $io->success(\sprintf('keys.json serial %d is published; active list keys: %s.', $result['serial'], implode(', ', array_keys($result['listKeys']))));

        foreach ($this->signer->publicKeys() as $kid => $public) {
            if (($result['listKeys'][$kid] ?? null) !== $public) {
                $io->warning(\sprintf('The configured signing key "%s" is not an active key in this key set: clients will not accept lists signed only with it.', $kid));
            }
        }

        return Command::SUCCESS;
    }
}
