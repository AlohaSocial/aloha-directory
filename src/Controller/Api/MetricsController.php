<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Monitoring\Metrics;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MetricsController
{
    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function __invoke(
        Request $request,
        Metrics $metrics,
        #[Autowire('%env(default::METRICS_TOKEN)%')] ?string $token,
    ): Response {
        if (null === $token || '' === $token) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain']);
        }
        $given = (string) $request->headers->get('Authorization', '');
        if (!hash_equals('Bearer '.$token, $given)) {
            return new Response('Unauthorized', 401, ['Content-Type' => 'text/plain', 'WWW-Authenticate' => 'Bearer']);
        }

        return new Response($metrics->prometheus(), 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }
}
