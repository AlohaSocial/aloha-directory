<?php

declare(strict_types=1);

namespace App\Command;

use App\Crypto\Base64Url;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app:secrets:generate-encryption-key', 'Prints a new value for encryption_key in config.php')]
final class GenerateEncryptionKeyCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->writeln(Base64Url::encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)));

        return Command::SUCCESS;
    }
}
