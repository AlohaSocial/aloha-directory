<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\Metrics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:monitor', 'Prints the alarms of concept 13.8; exits with 1 when one fires (for cron or monitoring)')]
final class MonitorCommand
{
    public function __construct(private readonly Metrics $metrics)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $alarms = $this->metrics->alarms();
        if ([] === $alarms) {
            $io->success('No alarms.');

            return Command::SUCCESS;
        }
        $io->error($alarms);

        return Command::FAILURE;
    }
}
