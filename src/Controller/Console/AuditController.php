<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Audit\AuditChain;
use App\Entity\AuditHead;
use App\Repository\AuditLogRepository;
use App\Service\SystemStateStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuditController extends AbstractConsoleController
{
    private const PAGE = 100;

    #[Route('/console/audit', name: 'console_audit', methods: ['GET'])]
    public function __invoke(Request $request, AuditLogRepository $audit, AuditChain $chain, SystemStateStore $state, EntityManagerInterface $em): Response
    {
        $filters = [
            'action' => mb_substr((string) $request->query->get('action', ''), 0, 64),
            'domain' => mb_substr((string) $request->query->get('domain', ''), 0, 253),
            'actor' => mb_substr((string) $request->query->get('actor', ''), 0, 254),
        ];
        $before = $request->query->get('before');
        $entries = $audit->search($filters['action'], $filters['domain'], $filters['actor'], \is_string($before) ? $before : null, self::PAGE + 1);
        $more = \count($entries) > self::PAGE;

        return $this->render('console/audit.html.twig', [
            'entries' => \array_slice($entries, 0, self::PAGE),
            'next' => $more ? $entries[self::PAGE - 1]->id : null,
            'filters' => $filters,
            'actions' => $audit->distinctActions(),
            'chain' => [
                'head' => $chain->head(),
                'backlog' => $chain->backlog(),
                'verification' => json_decode((string) $state->get(SystemStateStore::AUDIT_VERIFICATION)?->value, true),
                'published' => $em->getRepository(AuditHead::class)->findBy([], ['day' => 'DESC'], 1)[0] ?? null,
            ],
        ]);
    }
}
