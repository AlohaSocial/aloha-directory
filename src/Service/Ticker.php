<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditChain;
use App\Enum\ServerState;
use App\Lists\ListBuilder;
use App\Lists\MirrorPusher;
use App\Media\MediaFetcher;
use App\Repository\ServerRepository;
use App\Site\SiteBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The job that runs every minute (concept 13.2): time-based state rules, pending media, list
 * rebuilds, and — when a list changed — the static website and the Git mirror.
 */
final class Ticker
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly ServerLifecycle $lifecycle,
        private readonly MediaFetcher $media,
        private readonly ListBuilder $lists,
        private readonly SiteBuilder $site,
        private readonly MirrorPusher $mirror,
        private readonly SystemStateStore $state,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $locks,
        private readonly AuditChain $auditChain,
    ) {
    }

    /**
     * @return array{evaluated: int, media: int, builds: list<string>, site: bool, mirror: bool}
     */
    public function tick(bool $force = false, bool $renderSite = false): array
    {
        $lock = $this->locks->createLock('aloha-directory-tick', 300);
        if (!$lock->acquire()) {
            return ['evaluated' => 0, 'media' => 0, 'builds' => [], 'site' => false, 'mirror' => false];
        }
        try {
            $servers = $this->servers->findInStates(ServerState::Listed, ServerState::Paused);
            foreach ($servers as $server) {
                if ($this->lifecycle->needsEvaluation($server)) {
                    $this->lifecycle->underLock($server, $this->lifecycle->evaluate(...));
                }
            }

            $media = $this->media->processPending();

            $builds = $this->lists->buildDue($force, $force ? 'manual' : 'change');
            $site = false;
            if ([] !== $builds || $renderSite) {
                $this->site->build();
                $site = true;
            }
            $mirror = $this->mirror->push($builds);

            $this->state->set(SystemStateStore::LAST_TICK, (new \DateTimeImmutable())->format(\DATE_ATOM));
            $this->em->flush();
            $this->auditChain->seal();

            return [
                'evaluated' => \count($servers),
                'media' => $media,
                'builds' => array_map(static fn ($b): string => $b->list.'#'.$b->serial, $builds),
                'site' => $site,
                'mirror' => $mirror,
            ];
        } finally {
            $lock->release();
        }
    }
}
