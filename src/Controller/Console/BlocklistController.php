<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Entity\BlockedDomain;
use App\Service\AuditLogger;
use App\Service\Blocklist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The directory's own block list (concept 7.2). Shared lists are imported with app:blocklist:import.
 */
#[Route('/console/blocklist')]
final class BlocklistController extends AbstractConsoleController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'console_blocklist', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $repo = $this->em->getRepository(BlockedDomain::class);
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        $rows = $this->em->createQueryBuilder()->select('b.source AS source, COUNT(b.id) AS n')->from(BlockedDomain::class, 'b')->groupBy('b.source')->getQuery()->getArrayResult();
        $matches = '' === $q ? [] : $this->em->createQueryBuilder()->select('b')->from(BlockedDomain::class, 'b')
            ->where('b.domain LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_\\').'%')->setMaxResults(100)->getQuery()->getResult();

        return $this->render('console/blocklist.html.twig', [
            'own' => $repo->findBy(['source' => BlockedDomain::SOURCE_DIRECTORY], ['domain' => 'ASC']),
            'sources' => $rows,
            'q' => $q,
            'matches' => $matches,
        ]);
    }

    #[Route('/add', name: 'console_blocklist_add', methods: ['POST'])]
    public function add(Request $request, Blocklist $blocklist): RedirectResponse
    {
        $this->requireCsrf($request);
        try {
            $block = $blocklist->add((string) $request->request->get('domain'), BlockedDomain::SOURCE_DIRECTORY, $this->text($request, 'reason', 500));
            $this->audit->curator($this->curator(), 'blocklist.added', null, ['domain' => $block->domain, 'reason' => $block->reason]);
            $this->em->flush();
            $this->addFlash('success', \sprintf('%s is blocked.', $block->domain));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('console_blocklist');
    }

    #[Route('/{id}/remove', name: 'console_blocklist_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(Request $request, int $id): RedirectResponse
    {
        $this->requireCsrf($request);
        $block = $this->em->find(BlockedDomain::class, $id);
        if (null === $block || BlockedDomain::SOURCE_DIRECTORY !== $block->source) {
            throw new NotFoundHttpException();
        }
        $this->audit->curator($this->curator(), 'blocklist.removed', null, ['domain' => $block->domain]);
        $this->em->remove($block);
        $this->em->flush();
        $this->addFlash('success', \sprintf('%s is no longer blocked.', $block->domain));

        return $this->redirectToRoute('console_blocklist');
    }
}
