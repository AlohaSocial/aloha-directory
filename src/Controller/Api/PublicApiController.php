<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\JsonBody;
use App\Api\RateLimits;
use App\Enum\ListName;
use App\Service\ApiProblem;
use App\Service\ComplaintIntake;
use App\Service\SystemStateStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicApiController extends AbstractController
{
    public function __construct(private readonly RateLimits $limits)
    {
    }

    #[Route('/api/v1/reports', name: 'api_report', methods: ['POST'], format: 'json')]
    public function report(Request $request, ComplaintIntake $intake): JsonResponse
    {
        $this->limits->consume('report', (string) $request->getClientIp());
        $complaint = $intake->submit(JsonBody::read($request, 16_384)['data']);

        return new JsonResponse(['received' => true, 'id' => $complaint->id], 202);
    }

    /**
     * Clients report lists that failed to verify (concept 13.8). Only a counter per list and reason
     * is kept, nothing about the client.
     */
    #[Route('/api/v1/lists/failures', name: 'api_list_failure', methods: ['POST'], format: 'json')]
    public function listFailure(Request $request, SystemStateStore $state): Response
    {
        $this->limits->consume('telemetry', (string) $request->getClientIp());
        $data = JsonBody::read($request, 512)['data'];
        $list = ListName::tryFrom(\is_string($data['list'] ?? null) ? $data['list'] : '');
        $reason = $data['reason'] ?? null;
        $reasons = ['signature', 'serial', 'format', 'expired', 'keys'];
        if (null === $list || !\in_array($reason, $reasons, true)) {
            throw new ApiProblem(422, 'validation_failed', 'list must be servers, search or keys; reason one of: '.implode(', ', $reasons).'.');
        }
        $state->increment('list_failure.'.$list->value.'.'.$reason);

        return new Response(null, 204);
    }

    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'degraded', 'database' => false], 503);
        }

        return new JsonResponse(['status' => 'ok', 'database' => true]);
    }
}
