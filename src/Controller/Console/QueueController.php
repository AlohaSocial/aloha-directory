<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Repository\ComplaintRepository;
use App\Repository\ServerRepository;
use App\Service\DirectorySettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The curator queue (concept 13.4): everything that waits for a person, oldest first.
 */
final class QueueController extends AbstractConsoleController
{
    public const TABS = [
        'applications' => 'Applications',
        'profiles' => 'Profile changes',
        'complaints' => 'Complaints',
        'paused' => 'Paused automatically',
        'reviews' => 'Re-reviews due',
        'waitlist' => 'Waiting list',
        'checks' => 'Automatic checks running',
    ];

    #[Route('/console', name: 'console_queue', methods: ['GET'])]
    public function __invoke(
        Request $request,
        ServerRepository $servers,
        ComplaintRepository $complaints,
        DirectorySettings $settings,
        ClockInterface $clock,
    ): Response {
        $reviewBefore = $clock->now()->modify('+'.$settings->int('review_due_warning_days').' days');
        $items = [
            'applications' => $servers->findInReview(),
            'profiles' => $servers->findWithPendingProfile(),
            'complaints' => $complaints->findOpen(),
            'paused' => $servers->findPaused(true),
            'reviews' => $servers->findReviewDue($reviewBefore),
            'waitlist' => $servers->findInStates(\App\Enum\ServerState::Waitlisted),
            'checks' => $servers->findApplied(),
        ];
        $tab = (string) $request->query->get('tab', '');
        if (!isset(self::TABS[$tab])) {
            $tab = 'applications';
            foreach (array_keys(self::TABS) as $candidate) {
                if ([] !== $items[$candidate]) {
                    $tab = $candidate;
                    break;
                }
            }
        }

        return $this->render('console/queue.html.twig', [
            'tabs' => self::TABS,
            'tab' => $tab,
            'counts' => array_map('count', $items),
            'items' => $items[$tab],
            'me' => $this->curator(),
        ]);
    }
}
