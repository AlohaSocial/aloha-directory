<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogger;
use App\Service\Blocklist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:blocklist:import', 'Replaces a shared block list (e.g. oliphant-tier0) with the domains in a CSV or text file')]
final class BlocklistImportCommand
{
    public function __construct(
        private readonly Blocklist $blocklist,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Name of the list, e.g. oliphant-tier0')] string $source,
        #[Argument('CSV (Mastodon export) or one domain per line')] string $file,
    ): int {
        if (1 !== preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $source)) {
            $io->error('The source name may contain a-z, 0-9, ".", "_" and "-".');

            return Command::INVALID;
        }
        $contents = @file_get_contents($file);
        if (false === $contents) {
            $io->error('Cannot read '.$file);

            return Command::FAILURE;
        }
        $result = $this->blocklist->importSource($source, $contents);
        $this->audit->system('blocklist.imported', null, ['source' => $source] + $result);
        $this->em->flush();
        $io->success(\sprintf('%s: %d added, %d removed, %d lines skipped.', $source, $result['added'], $result['removed'], $result['skipped']));

        return Command::SUCCESS;
    }
}
