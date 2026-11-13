<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Maintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:maintenance', 'Daily: retention, aggregation and cleanup (cron: 30 3 * * *)')]
final class MaintenanceCommand
{
    public function __construct(private readonly Maintenance $maintenance)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach ($this->maintenance->run() as $name => $count) {
            if ($io->isVerbose()) {
                $io->writeln(\sprintf('%-28s %d', $name, $count));
            }
        }

        return Command::SUCCESS;
    }
}
