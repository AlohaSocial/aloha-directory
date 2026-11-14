<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ticker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:tick', 'Every minute: automatic states, media, list rebuilds, website and mirror (cron: * * * * *)')]
final class TickCommand
{
    public function __construct(private readonly Ticker $ticker)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Rebuild and re-sign every list even if nothing changed')] bool $force = false,
        #[Option('Render the public website even if no list changed')] bool $site = false,
    ): int {
        $result = $this->ticker->tick($force, $site);
        if ($result['skipped']) {
            // The previous tick is still running (e.g. a slow mirror push); it does this run's work.
            if ($io->isVerbose()) {
                $io->writeln('Another tick is still running; skipped.');
            }

            return Command::SUCCESS;
        }
        if ($io->isVerbose() || [] !== $result['builds']) {
            $io->writeln(\sprintf('Evaluated %d servers, processed %d media, published: %s%s.',
                $result['evaluated'], $result['media'], [] === $result['builds'] ? 'nothing' : implode(', ', $result['builds']), $result['site'] ? ', website rendered' : ''));
        }

        return $result['mirror'] ? Command::SUCCESS : Command::FAILURE;
    }
}
