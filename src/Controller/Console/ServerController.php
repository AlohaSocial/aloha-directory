<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Check\Checker;
use App\Check\Checks;
use App\Console\Diff;
use App\Curation\Covenant;
use App\Curation\CurationService;
use App\Curation\FeaturedEligibility;
use App\Entity\Comment;
use App\Entity\CuratorConflict;
use App\Entity\Proposal;
use App\Entity\Review;
use App\Entity\Server;
use App\Entity\ServerCheck;
use App\Enum\CommentVisibility;
use App\Enum\ProposalAction;
use App\Enum\ServerState;
use App\Lists\ServerListEntryFactory;
use App\Net\DomainName;
use App\Repository\AuditLogRepository;
use App\Repository\ComplaintRepository;
use App\Repository\HeartbeatRepository;
use App\Repository\ProposalRepository;
use App\Repository\ServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/console/servers')]
final class ServerController extends AbstractConsoleController
{
    private const DOMAIN = '[a-z0-9.-]{3,253}';

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly CurationService $curation,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'console_servers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $state = ServerState::tryFrom((string) $request->query->get('state', ''));
        $query = mb_strtolower(trim((string) $request->query->get('q', '')));
        $list = null === $state ? $this->servers->findBy([], ['domain' => 'ASC']) : $this->servers->findInStates($state);
        if ('' !== $query) {
            $list = array_values(array_filter($list, static fn (Server $s): bool => str_contains($s->domain, $query) || str_contains(mb_strtolower($s->displayName()), $query)));
        }

        return $this->render('console/servers.html.twig', [
            'servers' => $list,
            'states' => ServerState::cases(),
            'state' => $state,
            'q' => $query,
            'counts' => $this->servers->countByState(),
        ]);
    }

    #[Route('/{domain}', name: 'console_server', requirements: ['domain' => self::DOMAIN], methods: ['GET'])]
    public function show(
        string $domain,
        HeartbeatRepository $heartbeats,
        ProposalRepository $proposals,
        ComplaintRepository $complaints,
        AuditLogRepository $audit,
        FeaturedEligibility $featured,
        ServerListEntryFactory $entries,
    ): Response {
        $server = $this->server($domain);
        $me = $this->curator();
        $checks = [];
        foreach ($this->em->getRepository(ServerCheck::class)->findBy(['server' => $server]) as $check) {
            $checks[$check->name] = $check;
        }

        return $this->render('console/server.html.twig', [
            'server' => $server,
            'me' => $me,
            'conflict' => $this->curation->conflict($me, $server),
            'conflicts' => $this->em->getRepository(CuratorConflict::class)->findBy(['server' => $server]),
            'checks' => $checks,
            'check_labels' => Checks::LABELS,
            'heartbeats' => $heartbeats->latestForServer($server, 48),
            'availability' => $featured->availabilityPercent($server),
            'featured_unmet' => ServerState::Listed === $server->state && !$server->featured ? $featured->unmet($server) : [],
            'proposals' => $proposals->findForServer($server),
            'comments' => $this->em->getRepository(Comment::class)->findBy(['server' => $server, 'complaint' => null], ['createdAt' => 'DESC'], 100),
            'reviews' => $this->em->getRepository(Review::class)->findBy(['server' => $server], ['createdAt' => 'DESC'], 20),
            'complaints' => $complaints->findBy(['server' => $server], ['createdAt' => 'DESC'], 50),
            'audit' => $audit->search(null, $server->domain, null, null, 50),
            'profile_diff' => Diff::fields($server->approvedProfile, $server->profile),
            'pending_diff' => null === $server->pendingProfile ? [] : Diff::fields($server->profile, $server->pendingProfile),
            'entry' => ServerState::Listed === $server->state && null !== $server->profile ? $entries->entry($server) : null,
            'actions' => ProposalAction::cases(),
            'initial_checklist' => Covenant::INITIAL_CHECKLIST,
            'rereview_checklist' => Covenant::REREVIEW_CHECKLIST,
            'delisting_reasons' => Covenant::delistingReasons(),
        ]);
    }

    #[Route('/{domain}/propose', name: 'console_server_propose', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function propose(Request $request, string $domain): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);
        $action = ProposalAction::tryFrom((string) $request->request->get('action'));
        if (null === $action) {
            throw new NotFoundHttpException();
        }
        $params = [
            'weight' => $request->request->get('weight'),
            'rule' => $request->request->get('rule'),
            'public_reason' => $this->text($request, 'public_reason', 1000),
        ];

        return $this->act(
            fn () => $this->curation->propose($this->curator(), $server, $action, $params, $this->text($request, 'reason', 2000)),
            \sprintf('Proposal "%s" created. A second curator has to confirm it.', $action->label()),
            $this->url($server),
        );
    }

    #[Route('/{domain}/proposals/{id}/confirm', name: 'console_proposal_confirm', requirements: ['domain' => self::DOMAIN, 'id' => '\d+'], methods: ['POST'])]
    public function confirm(Request $request, string $domain, int $id): RedirectResponse
    {
        $this->requireCsrf($request);
        $proposal = $this->proposal($domain, $id);

        return $this->act(fn () => $this->curation->confirm($this->curator(), $proposal, $this->text($request, 'note', 2000)), 'Confirmed. The published list updates within a minute.', $this->url($proposal->server));
    }

    #[Route('/{domain}/proposals/{id}/decline', name: 'console_proposal_decline', requirements: ['domain' => self::DOMAIN, 'id' => '\d+'], methods: ['POST'])]
    public function decline(Request $request, string $domain, int $id): RedirectResponse
    {
        $this->requireCsrf($request);
        $proposal = $this->proposal($domain, $id);

        return $this->act(fn () => $this->curation->decline($this->curator(), $proposal, $this->text($request, 'note', 2000)), 'The proposal is closed.', $this->url($proposal->server));
    }

    #[Route('/{domain}/pause', name: 'console_server_pause', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function pause(Request $request, string $domain): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);

        return $this->act(fn () => $this->curation->pause($this->curator(), $server, $this->text($request, 'concern', 2000)), 'The server is paused and leaves the list within a minute. The admin was told.', $this->url($server));
    }

    #[Route('/{domain}/waitlist', name: 'console_server_waitlist', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function waitlist(Request $request, string $domain): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);

        return $this->act(fn () => $this->curation->waitlist($this->curator(), $server, $this->text($request, 'note', 2000)), 'The application is on the waiting list.', $this->url($server));
    }

    #[Route('/{domain}/comment', name: 'console_server_comment', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function comment(Request $request, string $domain): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);
        $visibility = 'admin' === $request->request->get('visibility') ? CommentVisibility::Admin : CommentVisibility::Internal;

        return $this->act(
            fn () => $this->curation->comment($this->curator(), $server, $this->text($request, 'body'), $visibility),
            CommentVisibility::Admin === $visibility ? 'The question was sent to the server admin.' : 'Note saved.',
            $this->url($server),
        );
    }

    #[Route('/{domain}/review', name: 'console_server_review', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function review(Request $request, string $domain): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);
        $kind = Review::KIND_REREVIEW === $request->request->get('kind') ? Review::KIND_REREVIEW : Review::KIND_INITIAL;
        $checked = $request->request->all('checklist');
        $checklist = [];
        foreach ($checked as $key => $value) {
            $checklist[(string) $key] = '1' === $value;
        }

        return $this->act(fn () => $this->curation->recordReview($this->curator(), $server, $kind, $checklist, $this->text($request, 'notes'), $this->text($request, 'fingerprint', 64)), 'Review recorded.', $this->url($server));
    }

    #[Route('/{domain}/profile/{decision}', name: 'console_server_profile', requirements: ['domain' => self::DOMAIN, 'decision' => 'approve|reject|revert'], methods: ['POST'])]
    public function profile(Request $request, string $domain, string $decision): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);
        $reason = $this->text($request, 'reason', 2000);

        return match ($decision) {
            'approve' => $this->act(fn () => $this->curation->approveProfileChanges($this->curator(), $server, $this->text($request, 'fingerprint', 64)), 'Profile changes accepted.', $this->url($server)),
            'reject' => $this->act(fn () => $this->curation->rejectProfileChanges($this->curator(), $server, $reason), 'Profile changes rejected; the admin was told why.', $this->url($server)),
            default => $this->act(fn () => $this->curation->revertLiveChanges($this->curator(), $server, $reason), 'Descriptions and images reverted to the approved version.', $this->url($server)),
        };
    }

    #[Route('/{domain}/conflict', name: 'console_server_conflict', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function conflict(Request $request, string $domain): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);
        $me = $this->curator();

        return $this->act(fn () => $this->curation->declareConflict($me, $me, $server, $this->text($request, 'note', 500)), 'Conflict of interest recorded. You will not review this server.', $this->url($server));
    }

    #[Route('/{domain}/check', name: 'console_server_check', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function check(Request $request, string $domain, Checker $checker): RedirectResponse
    {
        $this->requireCsrf($request);
        $server = $this->server($domain);
        $checker->run($server);
        $this->em->flush();
        $this->addFlash('success', 'The automatic checks ran again.');

        return $this->redirect($this->url($server));
    }

    private function server(string $domain): Server
    {
        $server = DomainName::isValid($domain) ? $this->servers->findOneByDomain($domain) : null;
        if (null === $server) {
            throw new NotFoundHttpException('Unknown server.');
        }

        return $server;
    }

    private function proposal(string $domain, int $id): Proposal
    {
        $proposal = $this->em->find(Proposal::class, $id);
        if (null === $proposal || $proposal->server->domain !== $domain) {
            throw new NotFoundHttpException();
        }

        return $proposal;
    }

    private function url(Server $server): string
    {
        return $this->generateUrl('console_server', ['domain' => $server->domain]);
    }
}
