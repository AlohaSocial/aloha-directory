<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditChain;
use App\Service\Maintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:audit:verify', 'Recomputes the audit hash chain and checks it against every published head')]
final class AuditVerifyCommand
{
    public function __construct(
        private readonly AuditChain $chain,
        private readonly Maintenance $maintenance,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Seal pending entries first')] bool $seal = false): int
    {
        if ($seal) {
            $io->writeln(\sprintf('Sealed %d entries.', $this->chain->seal(true)));
        }
        $result = $this->maintenance->verifyAuditChain();
        $head = $this->chain->head();
        if (!$result['ok']) {
            $io->error((string) $result['error']);

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d entries verify; %d published heads match. Head: entry %d, %s.', $result['entries'], $result['heads_checked'], $head['seq'], $head['hash']));

        return Command::SUCCESS;
    }
}
