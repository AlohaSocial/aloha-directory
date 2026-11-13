<?php

declare(strict_types=1);

namespace App\Command;

use App\Site\SiteBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:site:build', 'Renders the static public website')]
final class SiteBuildCommand
{
    public function __construct(private readonly SiteBuilder $site)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d pages written.', \count($this->site->build())));

        return Command::SUCCESS;
    }
}
