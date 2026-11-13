<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Console\Diff;
use App\Entity\ListBuild;
use App\Entity\ListState;
use App\Enum\ListName;
use App\Lists\ListBuilder;
use App\Repository\ListBuildRepository;
use App\Service\AuditLogger;
use App\Service\Ticker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/console/lists')]
final class ListController extends AbstractConsoleController
{
    #[Route('', name: 'console_lists', methods: ['GET'])]
    public function index(Request $request, ListBuildRepository $builds, EntityManagerInterface $em): Response
    {
        $list = ListName::tryFrom((string) $request->query->get('list', 'servers')) ?? ListName::Servers;
        $page = max(1, $request->query->getInt('page', 1));

        $current = [];
        foreach ([ListName::Servers, ListName::Search, ListName::Keys] as $name) {
            $current[$name->value] = ['build' => $builds->latest($name->value), 'state' => $em->find(ListState::class, $name->value)];
        }

        return $this->render('console/lists.html.twig', [
            'current' => $current,
            'list' => $list,
            'history' => $builds->history($list->value, 50, ($page - 1) * 50),
            'page' => $page,
        ]);
    }

    #[Route('/diff', name: 'console_lists_diff', methods: ['GET'])]
    public function diff(Request $request, EntityManagerInterface $em): Response
    {
        $a = $em->find(ListBuild::class, $request->query->getInt('a'));
        $b = $em->find(ListBuild::class, $request->query->getInt('b'));
        if (null === $a || null === $b || $a->list !== $b->list) {
            throw new NotFoundHttpException('Choose two builds of the same list.');
        }
        if ((int) $a->serial > (int) $b->serial) {
            [$a, $b] = [$b, $a];
        }

        return $this->render('console/list_diff.html.twig', [
            'a' => $a,
            'b' => $b,
            'diff' => Diff::entries((array) json_decode($a->payload, true), (array) json_decode($b->payload, true)),
        ]);
    }

    #[Route('/{id}', name: 'console_list_build', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $build = $em->find(ListBuild::class, $id) ?? throw new NotFoundHttpException();
        $pretty = json_encode(json_decode($build->payload), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $this->render('console/list_build.html.twig', ['build' => $build, 'pretty' => $pretty]);
    }

    /**
     * Releases a build held by the change guard. The form carries the hash of the held content the
     * curator looked at; if the content changed meanwhile, nothing is released.
     */
    #[Route('/{list}/release', name: 'console_lists_release', requirements: ['list' => 'servers|search'], methods: ['POST'])]
    public function release(Request $request, string $list, Ticker $ticker, AuditLogger $audit, EntityManagerInterface $em): RedirectResponse
    {
        $this->requireCsrf($request);
        $state = $em->find(ListState::class, $list);
        $seen = (string) $request->request->get('held_hash', '');
        $reason = trim(mb_substr((string) $request->request->get('reason', ''), 0, 1000));
        if (null === $state?->heldHash || !hash_equals($state->heldHash, $seen)) {
            $this->addFlash('error', 'The held change is different now (or was resolved). Please look again.');

            return $this->redirectToRoute('console_lists');
        }
        if ('' === $reason) {
            $this->addFlash('error', 'Please say why this change is intended.');

            return $this->redirectToRoute('console_lists');
        }
        $state->releasedHash = $state->heldHash;
        $audit->curator($this->curator(), 'list.released', null, ['list' => $list, 'reason' => $reason] + ($state->heldSummary ?? []));
        $em->flush();
        $result = $ticker->tick();
        $this->addFlash('success', [] === $result['builds'] ? 'Released; it is published with the next minute job.' : 'Released and published: '.implode(', ', $result['builds']).'.');

        return $this->redirectToRoute('console_lists');
    }

    #[Route('/rebuild', name: 'console_lists_rebuild', methods: ['POST'])]
    public function rebuild(Request $request, Ticker $ticker, AuditLogger $audit, EntityManagerInterface $em): RedirectResponse
    {
        $this->requireCsrf($request);
        $audit->curator($this->curator(), 'list.rebuild_requested');
        $em->flush();
        $result = $ticker->tick(true, true);
        $this->addFlash('success', [] === $result['builds'] ? 'Another build was running; try again in a minute.' : 'Published: '.implode(', ', $result['builds']).'.');

        return $this->redirectToRoute('console_lists');
    }
}
