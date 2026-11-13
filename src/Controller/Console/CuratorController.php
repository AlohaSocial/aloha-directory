<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Curation\CurationException;
use App\Curation\CurationService;
use App\Entity\Curator;
use App\Entity\CuratorConflict;
use App\Entity\CuratorInvite;
use App\Net\DomainName;
use App\Repository\CuratorRepository;
use App\Repository\ServerRepository;
use App\Security\CuratorAccounts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admins invite and deactivate curators, set roles and record conflicts of interest (concept 13.4).
 * Access is limited to ROLE_ADMIN in security.yaml.
 */
#[Route('/console/curators')]
final class CuratorController extends AbstractConsoleController
{
    public function __construct(
        private readonly CuratorRepository $curators,
        private readonly CuratorAccounts $accounts,
        private readonly CurationService $curation,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'console_curators', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $inviteUrl = $session->get('_aloha_invite_url');
        $session->remove('_aloha_invite_url');

        $response = $this->render('console/curators.html.twig', [
            'curators' => $this->curators->findBy([], ['active' => 'DESC', 'displayName' => 'ASC']),
            'invites' => $this->em->getRepository(CuratorInvite::class)->findBy(['usedAt' => null], ['createdAt' => 'DESC']),
            'conflicts' => $this->em->getRepository(CuratorConflict::class)->findBy([], ['createdAt' => 'DESC']),
            'invite_url' => \is_string($inviteUrl) ? $inviteUrl : null,
            'me' => $this->curator(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/invite', name: 'console_curators_invite', methods: ['POST'])]
    public function invite(Request $request): RedirectResponse
    {
        $this->requireCsrf($request);
        try {
            $url = $this->accounts->invite($this->curator(), (string) $request->request->get('email'), (string) $request->request->get('name'), '1' === $request->request->get('admin'));
            $request->getSession()->set('_aloha_invite_url', $url);
            $this->addFlash('success', 'Invitation created and sent by e-mail.');
        } catch (CurationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('console_curators');
    }

    #[Route('/{id}/{action}', name: 'console_curators_action', requirements: ['id' => '\d+', 'action' => 'activate|deactivate|make-admin|remove-admin'], methods: ['POST'])]
    public function action(Request $request, int $id, string $action): RedirectResponse
    {
        $this->requireCsrf($request);
        $curator = $this->em->find(Curator::class, $id) ?? throw new NotFoundHttpException();
        $me = $this->curator();
        $url = $this->generateUrl('console_curators');

        return match ($action) {
            'activate' => $this->act(fn () => $this->accounts->setActive($me, $curator, true), 'Curator activated.', $url),
            'deactivate' => $this->act(fn () => $this->accounts->setActive($me, $curator, false), 'Curator deactivated; their sessions have ended.', $url),
            'make-admin' => $this->act(fn () => $this->accounts->setAdmin($me, $curator, true), 'Admin role given.', $url),
            default => $this->act(fn () => $this->accounts->setAdmin($me, $curator, false), 'Admin role removed.', $url),
        };
    }

    #[Route('/conflicts', name: 'console_curators_conflict', methods: ['POST'])]
    public function addConflict(Request $request, ServerRepository $servers): RedirectResponse
    {
        $this->requireCsrf($request);
        $curator = $this->em->find(Curator::class, $request->request->getInt('curator'));
        $domain = (string) $request->request->get('domain');
        $server = DomainName::isValid($domain) ? $servers->findOneByDomain($domain) : null;
        if (null === $curator || null === $server) {
            $this->addFlash('error', 'Choose a curator and a known server domain.');

            return $this->redirectToRoute('console_curators');
        }

        return $this->act(fn () => $this->curation->declareConflict($this->curator(), $curator, $server, $this->text($request, 'note', 500)), 'Conflict of interest recorded.', $this->generateUrl('console_curators'));
    }

    #[Route('/conflicts/{id}/remove', name: 'console_curators_conflict_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeConflict(Request $request, int $id): RedirectResponse
    {
        $this->requireCsrf($request);
        $conflict = $this->em->find(CuratorConflict::class, $id) ?? throw new NotFoundHttpException();

        return $this->act(fn () => $this->curation->removeConflict($this->curator(), $conflict), 'Conflict removed.', $this->generateUrl('console_curators'));
    }
}
