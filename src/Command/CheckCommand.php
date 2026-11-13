<?php

declare(strict_types=1);

namespace App\Command;

use App\Check\Checker;
use App\Message\CheckServer;
use App\Net\DomainName;
use App\Repository\ServerRepository;
use App\Service\SystemStateStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Schedules the automatic checks of all enrolled servers (concept 7.2, 13.1): one job per server
 * on the "checks" queue, run by the workers (`messenger:consume verify checks`). Slow servers only
 * occupy a worker, never the schedule. --sync runs everything in this process instead.
 */
#[AsCommand('app:check', 'Every hour: queues the automatic checks of all enrolled servers (cron: 7 * * * *)')]
final class CheckCommand
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Checker $checker,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly SystemStateStore $state,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'messenger.transport.checks')]
        private readonly TransportInterface $checksQueue,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Check only this domain, now, in this process')] ?string $domain = null,
        #[Option('Run all checks in this process instead of queueing them')] bool $sync = false,
    ): int {
        if (null !== $domain) {
            $server = $this->servers->findOneByDomain(DomainName::normalize($domain));
            if (null === $server) {
                $io->error('Unknown server.');

                return Command::FAILURE;
            }
            foreach ($this->checker->run($server) as $check) {
                $io->writeln(\sprintf('%s %-16s %s', $check->passed ? '✔' : '✘', $check->name, $check->message));
            }
            $this->em->flush();

            return Command::SUCCESS;
        }

        if (!$sync && $this->checksQueue instanceof MessageCountAwareInterface && ($waiting = $this->checksQueue->getMessageCount()) > 0) {
            // The previous run has not finished: queueing again would only pile up duplicates.
            $this->logger->warning('{count} checks from the previous run are still waiting; not queueing new ones.', ['count' => $waiting]);
            $io->warning(\sprintf('%d checks from the previous run are still waiting. Add workers (messenger:consume checks).', $waiting));

            return Command::SUCCESS;
        }

        $ids = [];
        foreach ($this->servers->findActive() as $server) {
            if (null !== $server->id) {
                $ids[] = $server->id;
            }
        }
        $this->em->clear();

        $failures = 0;
        foreach ($ids as $id) {
            if (!$sync) {
                $this->bus->dispatch(new CheckServer($id));
                continue;
            }
            try {
                $server = $this->servers->find($id);
                if (null !== $server) {
                    $this->checker->run($server);
                    $this->em->flush();
                }
            } catch (\Throwable $e) {
                ++$failures;
                $this->logger->error('Checking a server failed.', ['server_id' => $id, 'exception' => $e]);
                if (!$this->em->isOpen()) {
                    return Command::FAILURE;
                }
            }
            $this->em->clear();
        }

        if ($sync) {
            $this->state->set(SystemStateStore::LAST_CHECK_RUN, (new \DateTimeImmutable())->format(\DATE_ATOM));
            $this->em->flush();
        }
        if ($io->isVerbose()) {
            $io->writeln(\sprintf('%s %d servers%s.', $sync ? 'Checked' : 'Queued', \count($ids), $sync ? \sprintf(', %d failures', $failures) : ''));
        }

        return 0 === $failures ? Command::SUCCESS : Command::FAILURE;
    }
}
