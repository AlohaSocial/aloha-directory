<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Complaint;
use App\Entity\CuratorInvite;
use App\Entity\PendingEnrolment;
use App\Entity\Server;
use App\Enum\ServerState;
use App\Media\MediaFetcher;
use App\Audit\AuditChain;
use App\Fairness\FairnessCalculator;
use App\Site\SiteBuilder;
use App\Lists\MirrorPusher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily retention and cleanup (concept 11.2): heartbeats are aggregated after 90 days, admin
 * contacts are removed a year after a server left, reporter contacts after a complaint closed,
 * and expired enrolments, invites and replay records are deleted. The audit log is permanent.
 */
final class Maintenance
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly DoctrineReplayGuard $replayGuard,
        private readonly MediaFetcher $media,
        private readonly SystemStateStore $state,
        private readonly AuditLogger $audit,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
        private readonly AuditChain $auditChain,
        private readonly MirrorPusher $mirror,
        private readonly Notifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly FairnessCalculator $fairness,
        private readonly SiteBuilder $site,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $now = $this->clock->now();
        $result = [
            'heartbeats_aggregated' => $this->aggregateHeartbeats($now),
            'nonces_purged' => $this->replayGuard->purgeExpired($now),
            'enrolments_expired' => $this->purgeExpiredEnrolments($now),
            'admin_contacts_removed' => $this->purgeAdminContacts($now),
            'reporter_contacts_removed' => $this->purgeReporterContacts($now),
            'invites_purged' => $this->purgeInvites($now),
            'list_builds_purged' => $this->purgeListBuilds($now),
            'check_history_purged' => (int) $this->connection->executeStatement('DELETE FROM server_check_daily WHERE day < ?', [$now->modify('-400 days')->format('Y-m-d')]),
            'media_deleted' => $this->media->collectGarbage(),
        ];
        $fairness = $this->fairness->recompute();
        $this->state->set(SystemStateStore::FAIRNESS, (string) json_encode(array_intersect_key($fairness, array_flip(['window_days', 'total_new_accounts', 'featured', 'hhi', 'top_share', 'top3_share']))));
        $result['fairness_hhi_permille'] = (int) round($fairness['hhi'] * 1000);
        $this->audit->system('maintenance.run', null, $result);
        $this->state->set(SystemStateStore::LAST_MAINTENANCE, $now->format(\DATE_ATOM));
        $this->em->flush();

        // Seal everything up to now (including the entry above), publish today's head and check the
        // whole chain against every head published so far.
        $this->auditChain->seal(true);
        $head = $this->auditChain->publishHead();
        if (null !== $head) {
            $this->mirror->pushFile('transparency/audit/'.$head->day.'.json', $head->envelope, 'Audit chain head '.$head->day.' (entry '.$head->seq.')');
        }
        $this->verifyAuditChain();
        // Daily, so uptime charts, check histories and audit heads on the website stay current.
        $this->site->build();

        return $result;
    }

    /**
     * @return array{ok: bool, entries: int, heads_checked: int, error: ?string}
     */
    public function verifyAuditChain(): array
    {
        $result = $this->auditChain->verify();
        $this->state->set(SystemStateStore::AUDIT_VERIFICATION, (string) json_encode($result + ['at' => $this->clock->now()->format(\DATE_ATOM)]));
        $this->em->flush();
        if (!$result['ok']) {
            $this->logger->critical('The audit hash chain does not verify.', $result);
            $this->notifier->curators('ALERT: the audit log was changed', "The daily check of the audit hash chain failed:\n\n".$result['error']."\n\nThe audit log may have been tampered with. Treat this as a security incident.");
        }

        return $result;
    }

    private function aggregateHeartbeats(\DateTimeImmutable $now): int
    {
        // Whole days only, so a day is never aggregated twice.
        $cutoff = $now->modify('-'.$this->settings->int('heartbeat_retention_days').' days')->setTime(0, 0);

        return $this->connection->transactional(function (Connection $c) use ($cutoff): int {
            $c->executeStatement(
                'INSERT INTO heartbeat_daily (server_id, day, heartbeats, min_available_accounts, max_approval_backlog, max_users_active_month, federation_failures)
                 SELECT server_id, DATE(received_at), COUNT(*), MIN(available_accounts), MAX(approval_backlog), MAX(users_active_month),
                        SUM(CASE WHEN federation_ok THEN 0 ELSE 1 END)
                 FROM heartbeat WHERE received_at < ? GROUP BY server_id, DATE(received_at)',
                [$cutoff], [Types::DATETIME_IMMUTABLE],
            );

            return (int) $c->executeStatement('DELETE FROM heartbeat WHERE received_at < ?', [$cutoff], [Types::DATETIME_IMMUTABLE]);
        });
    }

    private function purgeExpiredEnrolments(\DateTimeImmutable $now): int
    {
        return (int) $this->em->createQueryBuilder()
            ->delete(PendingEnrolment::class, 'p')
            ->where('p.expiresAt < :now')
            ->setParameter('now', $now)
            ->getQuery()->execute();
    }

    private function purgeAdminContacts(\DateTimeImmutable $now): int
    {
        $before = $now->modify('-'.$this->settings->int('admin_contact_retention_days').' days');
        $count = 0;
        foreach ($this->em->getRepository(Server::class)->findBy(['state' => [ServerState::Withdrawn, ServerState::Delisted, ServerState::Rejected]]) as $server) {
            $left = $server->withdrawnAt ?? $server->delistedAt ?? $server->rejectedAt;
            if (null !== $server->adminEmail && null !== $left && $left < $before) {
                $server->adminEmail = null;
                $server->abuseTestMailSentTo = null;
                ++$count;
            }
        }

        return $count;
    }

    private function purgeReporterContacts(\DateTimeImmutable $now): int
    {
        return (int) $this->em->createQueryBuilder()
            ->update(Complaint::class, 'c')
            ->set('c.reporterEmail', 'NULL')
            ->where('c.reporterEmail IS NOT NULL AND c.closedAt IS NOT NULL AND c.closedAt < :before')
            ->setParameter('before', $now->modify('-'.$this->settings->int('reporter_contact_retention_days').' days'))
            ->getQuery()->execute();
    }

    private function purgeInvites(\DateTimeImmutable $now): int
    {
        return (int) $this->em->createQueryBuilder()
            ->delete(CuratorInvite::class, 'i')
            ->where('i.expiresAt < :before')
            ->setParameter('before', $now->modify('-30 days'))
            ->getQuery()->execute();
    }

    private function purgeListBuilds(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement(
            // The latest build of each list is always kept.
            'DELETE FROM list_build WHERE issued_at < ? AND id NOT IN (SELECT id FROM (SELECT MAX(id) AS id FROM list_build GROUP BY list) latest)',
            [$now->modify('-'.$this->settings->int('list_build_retention_days').' days')],
            [Types::DATETIME_IMMUTABLE],
        );
    }
}
