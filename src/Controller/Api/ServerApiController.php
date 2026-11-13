<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\JsonBody;
use App\Api\RateLimits;
use App\Api\ServerAuthenticator;
use App\Api\StatusPresenter;
use App\Entity\Comment;
use App\Entity\Complaint;
use App\Entity\PendingEnrolment;
use App\Entity\Server;
use App\Enum\CommentVisibility;
use App\Message\VerifyEnrolment;
use App\Service\ApiProblem;
use App\Service\AuditLogger;
use App\Service\ServerLifecycle;
use App\Validation\HeartbeatValidator;
use App\Validation\InputReader;
use App\Validation\ProfileValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The API for Aloha servers (concept Appendix A.1). Everything except enrol is signed (RFC 9421).
 */
#[Route('/api/v1/servers', format: 'json')]
final class ServerApiController extends AbstractController
{
    private const DOMAIN = '[a-z0-9.-]{3,253}';
    private const MAX_QUEUED_VERIFICATIONS = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServerAuthenticator $auth,
        private readonly ServerLifecycle $lifecycle,
        private readonly StatusPresenter $status,
        private readonly AuditLogger $audit,
        private readonly RateLimits $limits,
        private readonly ClockInterface $clock,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/enrol', name: 'api_enrol', methods: ['POST'])]
    public function enrol(Request $request): JsonResponse
    {
        $this->limits->consume('enrol', (string) $request->getClientIp());
        $body = JsonBody::read($request, 4096)['data'];
        $domain = $body['domain'] ?? null;
        $key = $body['public_key'] ?? null;
        $contact = $body['contact_email'] ?? null;
        if (!\is_string($domain) || !\is_string($key) || (null !== $contact && !\is_string($contact))) {
            throw new ApiProblem(422, 'validation_failed', 'domain and public_key are required strings.');
        }

        $result = $this->lifecycle->enrol($domain, $key, $contact);
        $this->em->flush();
        $pending = $result['pending'];

        return new JsonResponse([
            'domain' => $pending->domain,
            'key_id' => $pending->keyId,
            'challenge' => $result['challenge'],
            'expires_at' => $pending->expiresAt->format('Y-m-d\TH:i:s\Z'),
            'well_known_url' => 'https://'.$pending->domain.'/.well-known/aloha-directory',
            'well_known_body' => ['challenge' => $result['challenge'], 'key_id' => $pending->keyId],
        ], 201);
    }

    #[Route('/{domain}/verify', name: 'api_verify', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function verify(Request $request, string $domain): JsonResponse
    {
        // verify makes outbound requests, so it is limited before anything else happens.
        $this->limits->consume('verify', (string) $request->getClientIp());
        $raw = JsonBody::read($request, 1024, true)['raw'];
        $keyId = $this->auth->authenticatePending($request, $domain, $raw);

        $now = $this->clock->now();
        $candidates = array_filter(
            $this->em->getRepository(PendingEnrolment::class)->findBy(['domain' => $domain, 'keyId' => $keyId]),
            static fn (PendingEnrolment $p): bool => $p->expiresAt >= $now,
        );
        if ([] === $candidates) {
            throw new ApiProblem(410, 'challenge_expired', 'The challenge has expired; enrol again.');
        }
        $queued = array_filter($candidates, static fn (PendingEnrolment $p): bool => PendingEnrolment::VERIFY_QUEUED === $p->verifyStatus
            && null !== $p->verifyRequestedAt && $p->verifyRequestedAt > $now->modify('-10 minutes'));
        if ([] === $queued) {
            // Per key, after the signature check, so nobody can use up another enrolment's attempts.
            $this->limits->consume('verify_key', $domain."\0".$keyId);
            // A ceiling on waiting verifications keeps the verify queue short for everyone.
            $waiting = (int) $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(PendingEnrolment::class, 'p')
                ->where('p.verifyStatus = :queued AND p.verifyRequestedAt > :recent')
                ->setParameter('queued', PendingEnrolment::VERIFY_QUEUED)->setParameter('recent', $now->modify('-10 minutes'))
                ->getQuery()->getSingleScalarResult();
            if ($waiting >= self::MAX_QUEUED_VERIFICATIONS) {
                throw new ApiProblem(503, 'verification_busy', 'Many verifications are waiting; try again in a few minutes.', [], ['Retry-After' => '120']);
            }
            foreach ($candidates as $pending) {
                $pending->verifyStatus = PendingEnrolment::VERIFY_QUEUED;
                $pending->verifyRequestedAt = $now;
                $pending->verifyFinishedAt = null;
                $pending->verifyError = null;
                $pending->verifyMessage = null;
            }
            $this->em->flush();
            $this->bus->dispatch(new VerifyEnrolment($domain, $keyId));
        }

        return new JsonResponse([
            'domain' => $domain,
            'status' => 'queued',
            'status_url' => '/api/v1/servers/'.$domain.'/verify',
            'retry_after_seconds' => 5,
        ], 202, ['Retry-After' => '5']);
    }

    /**
     * Outcome of the verification, signed with the pending key or (once verified) the new key.
     */
    #[Route('/{domain}/verify', name: 'api_verify_status', requirements: ['domain' => self::DOMAIN], methods: ['GET'])]
    public function verifyStatus(Request $request, string $domain): JsonResponse
    {
        $auth = $this->auth->authenticatePendingOrCurrent($request, $domain, '');
        if (!$auth['pending']) {
            $server = $this->em->getRepository(Server::class)->findOneBy(['domain' => $domain]);

            return new JsonResponse(['domain' => $domain, 'status' => 'verified', 'state' => $server?->state->value, 'key_id' => $auth['key_id']]);
        }
        $latest = null;
        foreach ($this->em->getRepository(PendingEnrolment::class)->findBy(['domain' => $domain, 'keyId' => $auth['key_id']]) as $pending) {
            if (null === $latest || $pending->verifyRequestedAt > $latest->verifyRequestedAt) {
                $latest = $pending;
            }
        }
        if (null === $latest) {
            throw new ApiProblem(401, 'unknown_key', 'Unknown key id.');
        }
        $body = [
            'domain' => $domain,
            'status' => $latest->verifyStatus ?? 'not_requested',
            'expires_at' => $latest->expiresAt->format('Y-m-d\TH:i:s\Z'),
        ];
        if (PendingEnrolment::VERIFY_FAILED === $latest->verifyStatus) {
            $body['error'] = $latest->verifyError;
            $body['message'] = $latest->verifyMessage;
        }

        return new JsonResponse($body, 200, PendingEnrolment::VERIFY_QUEUED === $latest->verifyStatus ? ['Retry-After' => '5'] : []);
    }

    #[Route('/{domain}/profile', name: 'api_profile', requirements: ['domain' => self::DOMAIN], methods: ['PUT'])]
    public function profile(Request $request, string $domain, ProfileValidator $validator): JsonResponse
    {
        $body = JsonBody::read($request, 65_536);
        $server = $this->auth->authenticate($request, $domain, $body['raw']);
        $profile = $validator->validate($body['data']);
        $contact = $body['data']['contact_email'] ?? null;
        $result = $this->lifecycle->acceptProfile($server, $profile, \is_string($contact) ? $contact : null);
        $this->em->flush();

        return new JsonResponse(['accepted' => true, 'pending_review' => $result['pending_review'], 'state' => $server->state->value]);
    }

    #[Route('/{domain}/heartbeat', name: 'api_heartbeat', requirements: ['domain' => self::DOMAIN], methods: ['PUT'])]
    public function heartbeat(Request $request, string $domain, HeartbeatValidator $validator): JsonResponse
    {
        $body = JsonBody::read($request, 8192);
        $server = $this->auth->authenticate($request, $domain, $body['raw']);
        $this->lifecycle->acceptHeartbeat($server, $validator->validate($body['data']));
        $this->em->flush();

        return new JsonResponse([
            'accepted' => true,
            'state' => $server->state->value,
            'profile_requested' => $server->profileRequested,
            'next_heartbeat_seconds' => 3600,
        ]);
    }

    #[Route('/{domain}/status', name: 'api_status', requirements: ['domain' => self::DOMAIN], methods: ['GET'])]
    public function status(Request $request, string $domain): JsonResponse
    {
        $server = $this->auth->authenticate($request, $domain, '');

        return new JsonResponse($this->status->present($server));
    }

    #[Route('/{domain}', name: 'api_withdraw', requirements: ['domain' => self::DOMAIN], methods: ['DELETE'])]
    public function withdraw(Request $request, string $domain): JsonResponse
    {
        $raw = JsonBody::read($request, 1024, true)['raw'];
        $server = $this->auth->authenticate($request, $domain, $raw);
        $this->lifecycle->withdraw($server);
        $this->em->flush();

        return new JsonResponse(['domain' => $server->domain, 'state' => $server->state->value]);
    }

    #[Route('/{domain}/key', name: 'api_rotate_key', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function rotateKey(Request $request, string $domain): JsonResponse
    {
        $body = JsonBody::read($request, 2048);
        $server = $this->auth->authenticate($request, $domain, $body['raw']);
        $key = $body['data']['public_key'] ?? null;
        $proof = $body['data']['proof'] ?? null;
        if (!\is_string($key) || !\is_string($proof)) {
            throw new ApiProblem(422, 'validation_failed', 'public_key and proof are required.');
        }
        $this->lifecycle->rotateKey($server, $key, $proof);
        $this->em->flush();

        return new JsonResponse(['domain' => $server->domain, 'key_id' => $server->keyId]);
    }

    /**
     * The admin answers a curator question (concept 4.2).
     */
    #[Route('/{domain}/comments', name: 'api_comment', requirements: ['domain' => self::DOMAIN], methods: ['POST'])]
    public function comment(Request $request, string $domain): JsonResponse
    {
        $body = JsonBody::read($request, 16_384);
        $server = $this->auth->authenticate($request, $domain, $body['raw']);
        if (!$server->state->isActive()) {
            throw new ApiProblem(409, 'not_active', 'The server is not enrolled.');
        }
        $text = $this->text($body['data']);
        $comment = new Comment($server, null, true, CommentVisibility::Admin, $text, $this->clock->now());
        $this->em->persist($comment);
        $this->audit->server($server, 'server.commented');
        $this->em->flush();

        return new JsonResponse(['id' => $comment->id], 201);
    }

    /**
     * The admin responds to a complaint that curators shared (concept 7.5).
     */
    #[Route('/{domain}/complaints/{id}/responses', name: 'api_complaint_response', requirements: ['domain' => self::DOMAIN, 'id' => '\d{1,10}'], methods: ['POST'])]
    public function complaintResponse(Request $request, string $domain, int $id): JsonResponse
    {
        $body = JsonBody::read($request, 16_384);
        $server = $this->auth->authenticate($request, $domain, $body['raw']);
        $complaint = $this->em->find(Complaint::class, $id);
        if (null === $complaint || $complaint->server !== $server || null === $complaint->sharedAt) {
            throw new ApiProblem(404, 'unknown_complaint', 'Unknown complaint.');
        }
        $comment = new Comment($server, null, true, CommentVisibility::Admin, $this->text($body['data']), $this->clock->now(), $complaint);
        $this->em->persist($comment);
        $this->audit->server($server, 'complaint.responded', ['complaint' => $complaint->id]);
        $this->em->flush();

        return new JsonResponse(['id' => $comment->id], 201);
    }

    /**
     * @param array<mixed> $data
     */
    private function text(array $data): string
    {
        $in = new InputReader($data);
        $text = $in->string('body', 5000, true, 1, true);
        if (null === $text) {
            throw new ApiProblem(422, 'validation_failed', 'body must be 1 to 5,000 characters.', $in->errors());
        }

        return $text;
    }
}
