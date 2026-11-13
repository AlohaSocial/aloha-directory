<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Complaint;
use App\Entity\TransparencyEntry;
use App\Entity\TransparencyReport;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The quarterly transparency report (concept 12.5): applications, approvals, rejections,
 * delistings by rule, appeals and complaints. Aggregates only; no personal data.
 */
final class TransparencyReporter
{
    public function __construct(
        private readonly AuditLogRepository $audit,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function quarterRange(string $quarter): array
    {
        if (1 !== preg_match('/\A(\d{4})-Q([1-4])\z/', $quarter, $m)) {
            throw new \InvalidArgumentException('The quarter must look like 2026-Q4.');
        }
        $from = new \DateTimeImmutable(\sprintf('%d-%02d-01 00:00:00 UTC', $m[1], 3 * ((int) $m[2] - 1) + 1));

        return [$from, $from->modify('+3 months')];
    }

    public static function previousQuarter(\DateTimeImmutable $now): string
    {
        $q = intdiv((int) $now->format('n') - 1, 3) + 1;
        $year = (int) $now->format('Y');

        return 1 === $q ? ($year - 1).'-Q4' : $year.'-Q'.($q - 1);
    }

    public function generate(string $quarter): TransparencyReport
    {
        [$from, $to] = self::quarterRange($quarter);
        if ($to > $this->clock->now()) {
            throw new \InvalidArgumentException('A quarter can only be reported once it has ended.');
        }
        $actions = $this->audit->countActionsBetween($from, $to);

        $delistings = [];
        $appeals = 0;
        $entries = $this->em->createQueryBuilder()->select('t')->from(TransparencyEntry::class, 't')
            ->where('t.decidedAt >= :from AND t.decidedAt < :to')->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getResult();
        foreach ($entries as $entry) {
            $delistings[$entry->rule] = ($delistings[$entry->rule] ?? 0) + 1;
            if (null !== $entry->appealOutcome) {
                ++$appeals;
            }
        }

        $complaints = [];
        $rows = $this->em->createQueryBuilder()->select('c.category AS category, COUNT(c.id) AS n')->from(Complaint::class, 'c')
            ->where('c.createdAt >= :from AND c.createdAt < :to')->setParameter('from', $from)->setParameter('to', $to)
            ->groupBy('c.category')->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $complaints[(string) $row['category']] = (int) $row['n'];
        }

        $data = [
            'applications' => $actions['server.verified'] ?? 0,
            'entered_review' => $actions['server.in_review'] ?? 0,
            'approvals' => $actions['server.listed'] ?? 0,
            'rejections' => $actions['server.rejected'] ?? 0,
            'waitlisted' => $actions['server.waitlisted'] ?? 0,
            'withdrawals' => $actions['server.withdrawn'] ?? 0,
            'pauses' => $actions['server.paused'] ?? 0,
            'delistings' => array_sum($delistings),
            'delistings_by_rule' => $delistings,
            'appeals_decided' => $appeals,
            'complaints' => array_sum($complaints),
            'complaints_by_category' => $complaints,
            'complaints_resolved' => $actions['complaint.resolved'] ?? 0,
            'complaints_dismissed' => $actions['complaint.dismissed'] ?? 0,
            'list_builds' => $actions['list.published'] ?? 0,
        ];

        $report = $this->em->getRepository(TransparencyReport::class)->findOneBy(['quarter' => $quarter]) ?? new TransparencyReport($quarter, $this->clock->now(), $data);
        $report->data = $data;
        $report->generatedAt = $this->clock->now();
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }
}
