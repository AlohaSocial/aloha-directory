<?php

declare(strict_types=1);

namespace App\Lists;

use App\Crypto\JsonCanonicalizer;
use App\Crypto\ListSigner;
use App\Crypto\ListVerifier;
use App\Crypto\SignedEnvelope;
use App\Entity\ListBuild;
use App\Entity\ListState;
use App\Entity\Server;
use App\Enum\ListName;
use App\Enum\ServerState;
use App\Service\AuditLogger;
use App\Repository\ListBuildRepository;
use App\Service\DirectorySettings;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Rebuilds, signs and publishes servers.json and search.json (concept 8, 13.2).
 *
 * Instead of trusting "changed" flags, every run recomputes the entries (a few hundred rows) and
 * compares their hash with the last build. Nothing is ever missed, including time-based changes
 * such as a heartbeat going stale, and nothing is rebuilt that did not change. Every list is
 * re-signed at least daily because it expires 30 days after issue.
 */
final class ListBuilder
{
    public const SIGNED_LISTS = [ListName::Servers, ListName::Search];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServerListEntryFactory $serverEntries,
        private readonly SearchListEntryFactory $searchEntries,
        private readonly ListSigner $signer,
        private readonly KeySetService $keySets,
        private readonly DirectorySettings $settings,
        private readonly AuditLogger $audit,
        private readonly LockFactory $locks,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ListBuildRepository $builds,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * @return list<ListBuild> the builds that were published
     */
    public function buildDue(bool $force = false, string $trigger = 'change'): array
    {
        $lock = $this->locks->createLock('aloha-directory-list-build', 120);
        if (!$lock->acquire(true)) {
            return [];
        }
        try {
            $builds = [];
            foreach (self::SIGNED_LISTS as $list) {
                $build = $this->buildOne($list, $force, $trigger);
                if (null !== $build) {
                    $builds[] = $build;
                }
            }

            return $builds;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{entries: list<array<string, mixed>>, static: array<string, mixed>}
     */
    public function content(ListName $list): array
    {
        return match ($list) {
            ListName::Servers => [
                'entries' => $this->serverEntries->entries(),
                'static' => [
                    'min_server_version' => $this->settings->minServerVersion,
                    'media_base_url' => $this->settings->publicUrl.'/media/',
                ],
            ],
            ListName::Search => ['entries' => $this->searchEntries->entries(), 'static' => []],
            ListName::Keys => throw new \LogicException('The key set is signed offline with the root key.'),
        };
    }

    public function path(ListName $list): string
    {
        return $this->settings->outputDir.'/lists/v1/'.$list->fileName();
    }

    private function buildOne(ListName $list, bool $force, string $trigger): ?ListBuild
    {
        $now = $this->clock->now();
        $state = $this->em->find(ListState::class, $list->value) ?? new ListState($list->value);
        $this->em->persist($state);

        $content = $this->content($list);
        $hash = hash('sha256', JsonCanonicalizer::canonicalize($content));
        $changed = $hash !== $state->entriesHash;
        $resignDue = null === $state->lastBuiltAt
            || $state->lastBuiltAt <= $now->modify('-'.$this->settings->int('resign_after_hours').' hours');
        $missing = !is_file($this->path($list));

        if ($changed) {
            $state->changePendingSince ??= $now;
        } elseif (null !== $state->heldSince) {
            // The held change went away by itself (for example, an outage ended).
            $this->audit->system('list.hold_cleared', null, ['list' => $list->value]);
            $state->heldSince = null;
            $state->heldHash = null;
            $state->heldSummary = null;
            $state->changePendingSince = null;
        }
        if (!$force && !$state->forceRebuild && !$changed && !$resignDue && !$missing) {
            $this->em->flush();

            return null;
        }

        $previous = $this->builds->latest($list->value);
        $guarded = null !== $previous && $changed ? $this->guard($list, $state, $previous, $content, $now) : ['held' => null, 'released' => false];
        $held = $guarded['held'];
        $released = $guarded['released'];
        if (null === $held && null !== $state->heldSince) {
            $state->heldSince = null;
            $state->heldHash = null;
            $state->heldSummary = null;
        }
        if (null !== $held && null !== $previous) {
            // Everything but the held part is published as usual (removals by curators at once), and
            // the result is re-signed when due, so the list never expires during a hold.
            $partialHash = hash('sha256', JsonCanonicalizer::canonicalize($held));
            if ($partialHash === $previous->entriesHash && !$resignDue && !$missing && !$force) {
                $this->em->flush();

                return null;
            }
            $content = $held;
            $hash = $partialHash;
        }

        // Serials only go up — also after a database restore, because they follow the clock.
        $serial = max((int) $state->serial + 1, $now->getTimestamp());
        $expires = $now->modify('+'.$this->settings->int('list_validity_days').' days');
        $payload = [
            'list' => $list->value,
            'version' => 1,
            'serial' => $serial,
            'issued_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
            ...$content['static'],
            'entries' => $content['entries'],
        ];

        $envelope = SignedEnvelope::build($payload, $this->signer->sign(...));
        $this->selfVerify($envelope['json'], $list, (int) $state->serial, $now);

        AtomicFileWriter::write($this->path($list), $envelope['json']);

        $reason = match (true) {
            null !== $held => 'partial-held',
            $released => 'released',
            $force || $state->forceRebuild => $trigger,
            $changed => 'change',
            $missing => 'missing',
            default => 'resign',
        };
        $keyIds = array_keys($this->signer->publicKeys());
        $build = new ListBuild(
            $list->value, (string) $serial, $now, $expires, hash('sha256', $envelope['json']), $hash,
            \count($content['entries']), $envelope['payload'], $envelope['json'], $keyIds, mb_substr($reason, 0, 32),
        );
        $this->em->persist($build);

        $state->serial = (string) $serial;
        $state->lastBuiltAt = $now;
        $state->entriesHash = $hash;
        if (null === $held) {
            $state->changePendingSince = null;
            $state->releasedHash = null;
        }
        $state->forceRebuild = false;
        $this->audit->system('list.published', null, ['list' => $list->value, 'serial' => $serial, 'entries' => \count($content['entries']), 'reason' => $reason]);
        $this->em->flush();

        $this->logger->info('Published {list} serial {serial}.', ['list' => $list->value, 'serial' => $serial, 'reason' => $reason]);

        return $build;
    }

    /**
     * The change guard (concept 11): a bug, an outage of the directory's own network or an attack
     * should never flood or empty the list in one step. Only two kinds of change count: servers
     * added, and servers removed because they were paused automatically (failing checks, missing
     * heartbeats, which is what an outage looks like). Removals decided by curators or by the
     * server itself (pause, delisting, withdrawal) are never held.
     *
     * When the counted changes exceed the limit, the build is held: the returned content is the
     * new content without the additions and with the automatically paused servers still in it.
     *
     * @param array{entries: list<array<string, mixed>>, static: array<string, mixed>} $content
     *
     * @return array{held: array{entries: list<array<string, mixed>>, static: array<string, mixed>}|null, released: bool}
     */
    private function guard(ListName $list, ListState $state, ListBuild $previous, array $content, \DateTimeImmutable $now): array
    {
        $oldEntries = [];
        foreach ((array) (json_decode($previous->payload, true)['entries'] ?? []) as $entry) {
            if (\is_array($entry) && \is_string($entry['domain'] ?? null)) {
                $oldEntries[$entry['domain']] = $entry;
            }
        }
        $newDomains = [];
        foreach ($content['entries'] as $entry) {
            if (\is_string($entry['domain'] ?? null)) {
                $newDomains[$entry['domain']] = true;
            }
        }
        $added = array_values(array_diff(array_keys($newDomains), array_keys($oldEntries)));
        $removed = array_values(array_diff(array_keys($oldEntries), array_keys($newDomains)));
        $autoRemoved = [];
        foreach ([] === $removed ? [] : $this->em->getRepository(Server::class)->findBy(['domain' => $removed]) as $server) {
            if (ServerState::Paused === $server->state && null !== $server->pauseKind && $server->pauseKind->isAutomatic()) {
                $autoRemoved[] = $server->domain;
            }
        }
        sort($added);
        sort($autoRemoved);

        $limit = max($this->settings->int('list_change_guard_min'), (int) ceil(\count($oldEntries) * $this->settings->int('list_change_guard_percent') / 100));
        if (\count($added) + \count($autoRemoved) <= $limit) {
            return ['held' => null, 'released' => false];
        }
        $changeHash = hash('sha256', (string) json_encode(['added' => $added, 'auto_removed' => $autoRemoved]));
        if (null !== $state->releasedHash && hash_equals($state->releasedHash, $changeHash)) {
            return ['held' => null, 'released' => true];
        }

        $addedSet = array_flip($added);
        $entries = [];
        foreach ($content['entries'] as $entry) {
            if (!isset($addedSet[$entry['domain'] ?? ''])) {
                $entries[] = $entry;
            }
        }
        foreach ($autoRemoved as $domain) {
            $entries[] = $oldEntries[$domain];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp((string) ($a['domain'] ?? $a['url'] ?? ''), (string) ($b['domain'] ?? $b['url'] ?? '')));

        if ($state->heldHash !== $changeHash) {
            $first = null === $state->heldSince;
            $summary = ['added' => $added, 'removed' => $autoRemoved, 'before' => \count($oldEntries), 'after' => \count($newDomains)];
            $state->heldSince ??= $now;
            $state->heldHash = $changeHash;
            $state->heldSummary = $summary;
            $this->audit->system('list.held', null, ['list' => $list->value] + $summary);
            if ($first) {
                $this->logger->warning('A build of {list} was held by the change guard.', ['list' => $list->value] + $summary);
                $this->notifier->curators(
                    \sprintf('%s.json held: %d servers added, %d paused automatically', $list->value, \count($added), \count($autoRemoved)),
                    \sprintf("The new build of %s.json would add %d servers and drop %d automatically paused ones (of %d) at once, more than the change guard allows (%d). These changes are held; everything else, including pauses and delistings by curators, is published as usual.\n\nPlease check whether this is intended, or an outage of the directory's network, a bug or an attack, and release it in the console:\n%s/console/lists\n\nAdded: %s\nPaused automatically: %s",
                        $list->value, \count($added), \count($autoRemoved), \count($oldEntries), $limit, $this->settings->publicUrl, implode(', ', $added) ?: '-', implode(', ', $autoRemoved) ?: '-'),
                );
            }
        }

        return ['held' => ['entries' => $entries, 'static' => $content['static']], 'released' => false];
    }

    /**
     * Verifies the signed result the way clients do before it is published: against the list keys
     * of the installed, root-signed keys.json, not against the keys that just signed it. A signing
     * key that is missing from keys.json (or revoked there) therefore fails here, instead of making
     * every client reject the list and keep a stale one.
     */
    private function selfVerify(string $json, ListName $list, int $previousSerial, \DateTimeImmutable $now): void
    {
        $keySet = $this->keySets->installed();
        if (null === $keySet) {
            throw new \RuntimeException('No valid keys.json is installed; install the root-signed key set before publishing lists (bin/console app:keys:install).');
        }
        $verifier = new ListVerifier([]);
        $verifier->verifyList($json, $keySet['listKeys'], $list->value, $previousSerial > 0 ? $previousSerial : null, $now);
    }
}
