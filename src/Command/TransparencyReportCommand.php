<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TransparencyReporter;
use App\Site\SiteBuilder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:transparency:report', 'Generates the quarterly transparency report (cron: 0 6 1 1,4,7,10 *)')]
final class TransparencyReportCommand
{
    public function __construct(
        private readonly TransparencyReporter $reporter,
        private readonly SiteBuilder $site,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Quarter like 2026-Q4; defaults to the last full quarter')] ?string $quarter = null): int
    {
        $quarter ??= TransparencyReporter::previousQuarter($this->clock->now());
        $report = $this->reporter->generate($quarter);
        $this->site->build();
        $io->success(\sprintf('Transparency report %s generated.', $report->quarter));

        return Command::SUCCESS;
    }
}
