<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Check\Checker;
use App\Entity\Server;
use App\Message\CheckServer;
use App\Service\SystemStateStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckServerHandler
{
    public function __construct(
        private readonly Checker $checker,
        private readonly EntityManagerInterface $em,
        private readonly SystemStateStore $state,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckServer $message): void
    {
        $server = $this->em->find(Server::class, $message->serverId);
        if (null === $server || !$server->state->isActive()) {
            return;
        }
        $this->checker->run($server);
        // When checks last actually ran (app:monitor warns when they stop, for example without a worker).
        $this->state->set(SystemStateStore::LAST_CHECK_RUN, $this->clock->now()->format(\DATE_ATOM));
        $this->em->flush();
    }
}
