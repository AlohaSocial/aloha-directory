<?php

declare(strict_types=1);

namespace App\Command;

use App\Crypto\ListVerifier;
use App\Crypto\VerificationException;
use App\Lists\KeySetService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:lists:verify', 'Verifies a list file the way clients do (pinned root key → keys.json → list)')]
final class VerifyListCommand
{
    public function __construct(private readonly KeySetService $keys)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('servers.json or search.json')] string $file,
        #[Option('keys.json to use')] ?string $keyset = null,
        #[Option('Expected list name')] string $list = 'servers',
    ): int {
        if (null === $keyset) {
            $io->error('--keyset is required.');

            return Command::INVALID;
        }
        $verifier = new ListVerifier($this->keys->rootKeys());
        try {
            $keys = $verifier->verifyKeySet((string) @file_get_contents($keyset), null, new \DateTimeImmutable());
            $result = $verifier->verifyList((string) @file_get_contents($file), $keys['listKeys'], $list, null, new \DateTimeImmutable());
        } catch (VerificationException $e) {
            $io->error('Verification failed: '.$e->getMessage());

            return Command::FAILURE;
        }
        $payload = $result['payload'];
        $io->success(\sprintf('%s.json serial %d, issued %s, %d entries%s.', $list, $payload['serial'], $payload['issued_at'], \count((array) $payload['entries']), $result['expired'] ? ' (EXPIRED)' : ''));

        return Command::SUCCESS;
    }
}
