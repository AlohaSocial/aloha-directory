<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\Base64Url;
use App\Curation\CurationException;
use App\Entity\Curator;
use App\Entity\CuratorPasskey;
use App\Service\DirectorySettings;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys (WebAuthn) as the second factor of the console. They are bound to the directory's
 * origin, so a phishing site cannot use them, unlike one-time codes.
 *
 *  - Challenges live in the server-side session, one per purpose, and are used once.
 *  - User verification (biometrics or PIN on the device) is required, and the password is checked
 *    as well, so a stolen device alone is not enough either.
 *  - Credentials are discoverable, so sign-in needs no "which account" round trip that would
 *    reveal whether an e-mail address belongs to a curator.
 *  - Only "none" attestation: the directory does not need to know the authenticator model.
 */
final class Passkeys
{
    public const MAX_PER_CURATOR = 10;
    private const SESSION_PREFIX = '_aloha_webauthn.';
    private const TIMEOUT_MS = 120_000;

    private readonly SerializerInterface $serializer;
    private readonly string $rpId;
    private readonly string $origin;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        DirectorySettings $settings,
    ) {
        $this->serializer = (new WebauthnSerializerFactory(new AttestationStatementSupportManager([new NoneAttestationStatementSupport()])))->create();
        $host = parse_url($settings->publicUrl, \PHP_URL_HOST);
        $this->rpId = \is_string($host) ? strtolower($host) : 'localhost';
        $port = parse_url($settings->publicUrl, \PHP_URL_PORT);
        $this->origin = (parse_url($settings->publicUrl, \PHP_URL_SCHEME) ?: 'https').'://'.$this->rpId.(null !== $port ? ':'.$port : '');
    }

    /**
     * Options for registering a new passkey; the JSON goes to the browser.
     */
    public function creationOptions(SessionInterface $session, string $purpose, string $userHandle, string $email, string $displayName, ?Curator $existing = null): string
    {
        $exclude = [];
        if (null !== $existing) {
            foreach ($this->passkeysOf($existing) as $passkey) {
                $exclude[] = PublicKeyCredentialDescriptor::create('public-key', Base64Url::decode($passkey->credentialId));
            }
        }
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('Aloha Directory', $this->rpId),
            PublicKeyCredentialUserEntity::create($email, Base64Url::decode($userHandle), $displayName),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_EDDSA),
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            self::TIMEOUT_MS,
        );

        return $this->store($session, $purpose, $options);
    }

    /**
     * Options for proving possession of a passkey; the JSON goes to the browser.
     */
    public function requestOptions(SessionInterface $session, string $purpose, ?Curator $curator = null): string
    {
        $allow = [];
        if (null !== $curator) {
            foreach ($this->passkeysOf($curator) as $passkey) {
                $allow[] = PublicKeyCredentialDescriptor::create('public-key', Base64Url::decode($passkey->credentialId));
            }
        }
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId,
            $allow,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::TIMEOUT_MS,
        );

        return $this->store($session, $purpose, $options);
    }

    /**
     * Verifies a registration and returns the credential (not yet persisted).
     */
    public function register(SessionInterface $session, string $purpose, string $responseJson, Curator $curator, string $name): CuratorPasskey
    {
        $options = $this->take($session, $purpose, PublicKeyCredentialCreationOptions::class);
        $credential = $this->parse($responseJson);
        if (null === $options || null === $credential || !$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new CurationException('The passkey could not be registered. Please try again.');
        }
        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                ->check($credential->response, $options, $this->rpId);
        } catch (\Throwable $e) {
            $this->logger->notice('Passkey registration failed.', ['exception' => $e]);
            throw new CurationException('The passkey could not be registered. Please try again.');
        }
        $credentialId = Base64Url::encode($record->publicKeyCredentialId);
        if (null !== $this->em->getRepository(CuratorPasskey::class)->findOneBy(['credentialId' => $credentialId])) {
            throw new CurationException('This passkey is registered already.');
        }
        if (null !== $curator->id && \count($this->passkeysOf($curator)) >= self::MAX_PER_CURATOR) {
            throw new CurationException(\sprintf('You can register at most %d passkeys.', self::MAX_PER_CURATOR));
        }
        $name = trim(mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '', 0, 64));

        return new CuratorPasskey($curator, $credentialId, $this->serializer->serialize($record, 'json'), '' === $name ? 'Passkey' : $name, $this->clock->now());
    }

    /**
     * Verifies an assertion for $curator and updates the stored counter. Returns false for any
     * failure, without telling why.
     */
    public function verify(SessionInterface $session, string $purpose, string $responseJson, Curator $curator): bool
    {
        $options = $this->take($session, $purpose, PublicKeyCredentialRequestOptions::class);
        $credential = $this->parse($responseJson);
        if (null === $options || null === $credential || !$credential->response instanceof AuthenticatorAssertionResponse || null === $curator->webauthnUserHandle) {
            return false;
        }
        $passkey = $this->em->getRepository(CuratorPasskey::class)->findOneBy(['credentialId' => Base64Url::encode($credential->rawId)]);
        if (null === $passkey || $passkey->curator->id !== $curator->id) {
            return false;
        }
        try {
            $stored = $this->serializer->deserialize($passkey->record, CredentialRecord::class, 'json');
            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check($stored, $credential->response, $options, $this->rpId, Base64Url::decode($curator->webauthnUserHandle));
        } catch (\Throwable $e) {
            $this->logger->notice('Passkey verification failed.', ['exception' => $e]);

            return false;
        }
        $passkey->record = $this->serializer->serialize($updated, 'json');
        $passkey->lastUsedAt = $this->clock->now();

        return true;
    }

    /**
     * @return list<CuratorPasskey>
     */
    public function passkeysOf(Curator $curator): array
    {
        return $this->em->getRepository(CuratorPasskey::class)->findBy(['curator' => $curator], ['createdAt' => 'ASC']);
    }

    public static function newUserHandle(): string
    {
        return Base64Url::encode(random_bytes(32));
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin]);
        $factory->setAlgorithmManager(Manager::create()->add(Ed25519::create(), ES256::create(), RS256::create()));

        return $factory;
    }

    private function store(SessionInterface $session, string $purpose, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        $json = $this->serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        ]);
        $session->set(self::SESSION_PREFIX.$purpose, ['json' => $json, 'at' => $this->clock->now()->getTimestamp()]);

        return $json;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function take(SessionInterface $session, string $purpose, string $class): ?object
    {
        $stored = $session->get(self::SESSION_PREFIX.$purpose);
        $session->remove(self::SESSION_PREFIX.$purpose); // single use
        if (!\is_array($stored) || !\is_string($stored['json'] ?? null) || !\is_int($stored['at'] ?? null)
            || $stored['at'] < $this->clock->now()->getTimestamp() - intdiv(self::TIMEOUT_MS, 1000) - 600) {
            return null;
        }
        try {
            $options = $this->serializer->deserialize($stored['json'], $class, 'json');
        } catch (\Throwable) {
            return null;
        }

        return $options instanceof $class ? $options : null;
    }

    private function parse(string $json): ?PublicKeyCredential
    {
        if ('' === $json || \strlen($json) > 16_384) {
            return null;
        }
        try {
            $credential = $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (\Throwable) {
            return null;
        }

        return $credential instanceof PublicKeyCredential ? $credential : null;
    }
}
