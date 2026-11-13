<?php

declare(strict_types=1);

namespace App\Command;

use App\Crypto\Base64Url;
use App\Crypto\KeyFile;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:keys:generate', 'Creates an Ed25519 key file (root key: on an offline machine only) and prints its public key')]
final class GenerateKeyCommand
{
    public function __invoke(SymfonyStyle $io, #[Argument('Path of the new key file (mode 0600)')] string $path): int
    {
        $public = KeyFile::generate($path);
        $io->success('Key written to '.$path);
        $io->writeln('Public key (base64url): '.Base64Url::encode($public));

        return Command::SUCCESS;
    }
}
