<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Entity\Server;
use App\Repository\ServerRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProviderController extends AbstractConsoleController
{
    #[Route('/console/providers', name: 'console_providers', methods: ['GET'])]
    public function __invoke(ServerRepository $servers): Response
    {
        $candidates = array_values(array_filter(
            $servers->findSearchCandidates(),
            static fn (Server $s): bool => $s->searchProvider || [] !== ($s->profile['search']['capabilities'] ?? []),
        ));

        return $this->render('console/providers.html.twig', ['servers' => $candidates]);
    }
}
