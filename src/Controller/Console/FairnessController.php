<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Fairness\FairnessCalculator;
use App\Service\DirectorySettings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * How newcomers spread across featured servers (concept 9.2, goal G3).
 */
final class FairnessController extends AbstractConsoleController
{
    #[Route('/console/fairness', name: 'console_fairness', methods: ['GET'])]
    public function __invoke(FairnessCalculator $fairness, DirectorySettings $settings): Response
    {
        return $this->render('console/fairness.html.twig', [
            'report' => $fairness->report(),
            'tolerance' => $settings->int('fairness_tolerance_percent'),
            'min_sample' => $settings->int('fairness_min_new_accounts'),
        ]);
    }
}
