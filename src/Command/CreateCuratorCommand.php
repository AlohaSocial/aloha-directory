<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\CuratorAccounts;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:curator:invite', 'Creates an invitation link for a curator (use --admin for the first account)')]
final class CreateCuratorCommand
{
    public function __construct(private readonly CuratorAccounts $accounts)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('E-mail address')] string $email,
        #[Argument('Display name')] string $name,
        #[Option('Give the admin role')] bool $admin = false,
    ): int {
        $url = $this->accounts->invite(null, $email, $name, $admin);
        $io->success('Invitation created. It is valid for 72 hours:');
        $io->writeln($url);

        return Command::SUCCESS;
    }
}
