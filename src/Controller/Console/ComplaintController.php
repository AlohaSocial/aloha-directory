<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Curation\CurationService;
use App\Entity\Comment;
use App\Entity\Complaint;
use App\Repository\ComplaintRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/console/complaints')]
final class ComplaintController extends AbstractConsoleController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurationService $curation,
    ) {
    }

    #[Route('', name: 'console_complaints', methods: ['GET'])]
    public function index(Request $request, ComplaintRepository $complaints): Response
    {
        $all = $request->query->getBoolean('all');

        return $this->render('console/complaints.html.twig', [
            'complaints' => $all ? $complaints->findRecent(200) : $complaints->findOpen(),
            'all' => $all,
            'categories' => Complaint::CATEGORIES,
        ]);
    }

    #[Route('/{id}', name: 'console_complaint', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $complaint = $this->complaint($id);

        return $this->render('console/complaint.html.twig', [
            'complaint' => $complaint,
            'categories' => Complaint::CATEGORIES,
            'thread' => $this->em->getRepository(Comment::class)->findBy(['complaint' => $complaint], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('/{id}/{action}', name: 'console_complaint_action', requirements: ['id' => '\d+', 'action' => 'share|reply|resolve|dismiss'], methods: ['POST'])]
    public function action(Request $request, int $id, string $action): RedirectResponse
    {
        $this->requireCsrf($request);
        $complaint = $this->complaint($id);
        $me = $this->curator();
        $url = $this->generateUrl('console_complaint', ['id' => $id]);

        return match ($action) {
            'share' => $this->act(fn () => $this->curation->shareComplaint($me, $complaint), 'The admin can now read the complaint (without your or the reporter\'s identity).', $url),
            'reply' => $this->act(fn () => $this->curation->replyToComplaint($me, $complaint, $this->text($request, 'body')), 'Reply sent to the admin.', $url),
            'resolve' => $this->act(fn () => $this->curation->closeComplaint($me, $complaint, false, $this->text($request, 'resolution', 2000)), 'Complaint resolved.', $url),
            default => $this->act(fn () => $this->curation->closeComplaint($me, $complaint, true, $this->text($request, 'resolution', 2000)), 'Complaint dismissed.', $url),
        };
    }

    private function complaint(int $id): Complaint
    {
        return $this->em->find(Complaint::class, $id) ?? throw new NotFoundHttpException();
    }
}
