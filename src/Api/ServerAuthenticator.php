<?php

declare(strict_types=1);

namespace App\Api;

use App\Crypto\Base64Url;
use App\Entity\PendingEnrolment;
use App\Entity\Server;
use App\Http\Signature\MessageSignatureVerifier;
use App\Net\DomainName;
use App\Repository\ServerRepository;
use App\Service\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates a server API request by its RFC 9421 signature. The server is looked up by the
 * domain in the URL, and the key id must be that server's current key. For verify, the key must
 * belong to a pending enrolment of that domain instead.
 */
final class ServerAuthenticator
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly MessageSignatureVerifier $verifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return string the key id of the verified pending key
     */
    public function authenticatePending(Request $request, string $domain, string $body): string
    {
        if (!DomainName::isValid($domain)) {
            throw new ApiProblem(404, 'unknown_server', 'Unknown server.');
        }
        $repository = $this->em->getRepository(PendingEnrolment::class);
        $matched = null;
        $this->verifier->verify($request, $body, static function (string $keyId) use ($repository, $domain, &$matched): ?string {
            $matched = $repository->findOneBy(['domain' => $domain, 'keyId' => $keyId]);

            return null === $matched ? null : Base64Url::decode($matched->publicKey);
        });
        if (!$matched instanceof PendingEnrolment) {
            throw new ApiProblem(401, 'unknown_key', 'Unknown key id.');
        }

        return $matched->keyId;
    }

    /**
     * For the verification status: the request may be signed by a pending key of the domain or by
     * the server's current key (after a successful verification the pending record is gone).
     *
     * @return array{key_id: string, pending: bool}
     */
    public function authenticatePendingOrCurrent(Request $request, string $domain, string $body): array
    {
        if (!DomainName::isValid($domain)) {
            throw new ApiProblem(404, 'unknown_server', 'Unknown server.');
        }
        $server = $this->servers->findOneByDomain($domain);
        $repository = $this->em->getRepository(PendingEnrolment::class);
        $matched = null;
        $this->verifier->verify($request, $body, static function (string $keyId) use ($repository, $server, $domain, &$matched): ?string {
            if (null !== $server && $keyId === $server->keyId && null !== $server->publicKey) {
                $matched = ['key_id' => $keyId, 'pending' => false];

                return Base64Url::decode($server->publicKey);
            }
            $pending = $repository->findOneBy(['domain' => $domain, 'keyId' => $keyId]);
            if (null === $pending) {
                return null;
            }
            $matched = ['key_id' => $keyId, 'pending' => true];

            return Base64Url::decode($pending->publicKey);
        });
        if (null === $matched) {
            throw new ApiProblem(401, 'unknown_key', 'Unknown key id.');
        }

        return $matched;
    }

    public function authenticate(Request $request, string $domain, string $body): Server
    {
        if (!DomainName::isValid($domain)) {
            throw new ApiProblem(404, 'unknown_server', 'Unknown server.');
        }
        $server = $this->servers->findOneByDomain($domain);
        if (null === $server) {
            throw new ApiProblem(401, 'unknown_key', 'Unknown key id.');
        }

        $this->verifier->verify($request, $body, static function (string $keyId) use ($server): ?string {
            $encoded = $keyId === $server->keyId ? $server->publicKey : null;

            return null === $encoded ? null : Base64Url::decode($encoded);
        });

        return $server;
    }
}
