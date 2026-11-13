<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Curator;
use App\Entity\Server;
use App\Security\Passkeys;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Boots the kernel with a fresh schema, a mock clock and a mock outbound HTTP client that answers
 * for every registered FakeAlohaServer.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected MockClock $clock;
    /** Always the container's current entity manager (the test client resets services between requests). */
    protected EntityManagerInterface $em {
        get => static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @var array<string, FakeAlohaServer> */
    protected array $fakes = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropDatabase();
        $tool->createSchema($metadata);

        $clock = $container->get(ClockInterface::class);
        \assert($clock instanceof MockClock);
        $this->clock = $clock;

        $http = $container->get('app.outbound_http_client');
        \assert($http instanceof MockHttpClient);
        $http->setResponseFactory(function (string $method, string $url): MockResponse {
            foreach ($this->fakes as $fake) {
                if (null !== ($response = $fake->respond($method, $url))) {
                    return $response;
                }
            }

            return new MockResponse('', ['error' => 'Could not resolve host']);
        });

        $fs = new Filesystem();
        $fs->remove($container->getParameter('kernel.project_dir').'/var/test-output');
        $container->get('cache.rate_limiter')->clear();
        TestQueueTransport::clear();
    }

    protected function fake(string $domain): FakeAlohaServer
    {
        return $this->fakes[$domain] ??= new FakeAlohaServer($domain);
    }

    /**
     * @param array<mixed>|null $body
     *
     * @return array<mixed>
     */
    protected function api(string $method, string $path, ?array $body = null, ?FakeAlohaServer $as = null, int $expectedStatus = 200): array
    {
        $raw = null === $body ? '' : (string) json_encode($body);
        $vars = null === $as
            ? ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']
            : $as->signedServerVars($method, $path, $raw, $this->clock->now()->getTimestamp(), 'directory.test-aloha.social');
        $this->client->request($method, 'https://directory.test-aloha.social'.$path, [], [], $vars, $raw);
        $response = $this->client->getResponse();
        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getContent());

        return '' === (string) $response->getContent() ? [] : (array) json_decode((string) $response->getContent(), true);
    }

    /**
     * Enrols and verifies a server and sends its profile. Returns it in state "applied".
     */
    protected function enrolServer(string $domain): FakeAlohaServer
    {
        $fake = $this->fake($domain);
        $result = $this->api('POST', '/api/v1/servers/enrol', ['domain' => $domain, 'public_key' => $fake->publicKey(), 'contact_email' => 'admin@'.$domain], null, 201);
        $fake->challenge = $result['challenge'];
        $this->verify($fake);
        $this->api('PUT', "/api/v1/servers/{$domain}/profile", Fixtures::profile($domain), $fake);

        return $fake;
    }

    /**
     * Requests verification (202), runs the queued job like a worker would, and returns the status
     * the server sees when it polls.
     *
     * @return array<mixed>
     */
    protected function verify(FakeAlohaServer $fake, string $expectedStatus = 'verified'): array
    {
        $queued = $this->api('POST', "/api/v1/servers/{$fake->domain}/verify", null, $fake, 202);
        self::assertSame('queued', $queued['status']);
        $this->runJobs();
        $this->clock->sleep(1);
        $status = $this->api('GET', "/api/v1/servers/{$fake->domain}/verify", null, $fake);
        self::assertSame($expectedStatus, $status['status'], (string) json_encode($status));

        return $status;
    }

    /**
     * Handles every queued background job, as `messenger:consume verify checks` would.
     *
     * @return int the number of jobs handled
     */
    protected function runJobs(): int
    {
        $container = static::getContainer();
        $bus = $container->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);
        $handled = 0;
        do {
            $progress = false;
            foreach (['verify', 'checks'] as $name) {
                $transport = $container->get('messenger.transport.'.$name);
                \assert($transport instanceof TestQueueTransport);
                foreach ($transport->get() as $envelope) {
                    $bus->dispatch($envelope->with(new ReceivedStamp($name)));
                    $transport->ack($envelope);
                    ++$handled;
                    $progress = true;
                }
            }
        } while ($progress);

        return $handled;
    }

    protected function heartbeat(FakeAlohaServer $fake, int $available = 412, int $backlog = 3, bool $open = true, int $expected = 200): array
    {
        return $this->api('PUT', "/api/v1/servers/{$fake->domain}/heartbeat", Fixtures::heartbeat($fake->domain, $this->clock->now(), $available, $backlog, $open), $fake, $expected);
    }

    /**
     * Sends three hourly heartbeats and runs the checker, which moves an application to "in review".
     */
    protected function bringToReview(FakeAlohaServer $fake): Server
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->heartbeat($fake);
            $this->clock->sleep(3600);
        }
        $this->heartbeat($fake);
        $server = $this->server($fake->domain);
        static::getContainer()->get(\App\Check\Checker::class)->run($server);
        $this->em->flush();

        return $this->server($fake->domain);
    }

    /**
     * Takes a server through review with two curators (alice proposes, bob confirms).
     */
    protected function listServer(FakeAlohaServer $fake): Server
    {
        $server = $this->bringToReview($fake);
        self::assertSame(\App\Enum\ServerState::InReview, $server->state);
        $alice = $this->em->getRepository(Curator::class)->findOneBy(['email' => 'alice@nextcloud.test']) ?? $this->createCurator('alice@nextcloud.test', true);
        $bob = $this->em->getRepository(Curator::class)->findOneBy(['email' => 'bob@nextcloud.test']) ?? $this->createCurator('bob@nextcloud.test');
        $curation = static::getContainer()->get(\App\Curation\CurationService::class);
        $curation->recordReview($alice, $server, \App\Entity\Review::KIND_INITIAL, array_fill_keys(array_keys(\App\Curation\Covenant::INITIAL_CHECKLIST), true), 'Looks good.');
        $proposal = $curation->propose($alice, $server, \App\Enum\ProposalAction::List, [], 'Adds a German-speaking server.');
        $curation->confirm($bob, $proposal);

        return $this->server($fake->domain);
    }

    /**
     * Publishes keys.json signed by the test root key, so lists can be verified end to end.
     */
    protected function installKeySet(): void
    {
        [$rootSecret] = TestKeys::root();
        [, $listPublic] = TestKeys::list();
        $json = \App\Lists\KeySetService::sign([['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($listPublic), 'status' => 'active']], 1, TestKeys::ROOT_KID, $rootSecret, $this->clock->now(), 365);
        static::getContainer()->get(\App\Lists\KeySetService::class)->install($json);
    }

    /**
     * @return array<string, mixed> the verified payload of a published list
     */
    protected function publishedList(string $list): array
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/lists/v1/';
        $verifier = new \App\Crypto\ListVerifier([TestKeys::ROOT_KID => TestKeys::root()[1]]);
        $keys = $verifier->verifyKeySet((string) file_get_contents($dir.'keys.json'), null, $this->clock->now());

        return $verifier->verifyList((string) file_get_contents($dir.$list.'.json'), $keys['listKeys'], $list, null, $this->clock->now())['payload'];
    }

    protected function tick(bool $force = false): array
    {
        return static::getContainer()->get(\App\Service\Ticker::class)->tick($force);
    }

    protected function server(string $domain): Server
    {
        $server = $this->em->getRepository(Server::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($server, $domain);
        $this->em->refresh($server);

        return $server;
    }

    /** @var array<string, SoftAuthenticator> the passkey of each curator created in this test */
    protected array $authenticators = [];

    /**
     * Creates a curator with a password and one passkey (registered through the real WebAuthn code).
     */
    protected function createCurator(string $email, bool $admin = false, string $password = 'correct horse battery staple'): Curator
    {
        $container = static::getContainer();
        $curator = new Curator($email, ucfirst(explode('@', $email)[0]), $this->clock->now());
        $curator->roles = $admin ? [Curator::ROLE_CURATOR, Curator::ROLE_ADMIN] : [Curator::ROLE_CURATOR];
        $curator->passwordHash = $container->get(UserPasswordHasherInterface::class)->hashPassword($curator, $password);
        $curator->webauthnUserHandle = Passkeys::newUserHandle();
        $this->em->persist($curator);
        $this->em->flush();

        $passkeys = $container->get(Passkeys::class);
        $session = new Session(new MockArraySessionStorage());
        $authenticator = new SoftAuthenticator();
        $options = $passkeys->creationOptions($session, 'test', $curator->webauthnUserHandle, $email, $curator->displayName);
        $this->em->persist($passkeys->register($session, 'test', $authenticator->create($options), $curator, 'Test key'));
        $this->em->flush();
        $this->authenticators[$email] = $authenticator;

        return $curator;
    }

    /**
     * Signs in through the login form: password plus the curator's passkey (or a recovery code).
     */
    protected function consoleLogin(string $email, string $password = 'correct horse battery staple', ?SoftAuthenticator $authenticator = null, string $recoveryCode = ''): void
    {
        $crawler = $this->client->request('GET', 'https://directory.test-aloha.social/console/login');
        $form = $crawler->filter('form[data-passkey-get]');
        $authenticator ??= $this->authenticators[$email] ?? null;
        $assertion = '' === $recoveryCode && null !== $authenticator ? $authenticator->get((string) $form->attr('data-passkey-get')) : '';
        $this->client->submit($form->form(['email' => $email, 'password' => $password, 'passkey' => $assertion, 'recovery_code' => $recoveryCode]));
        // A failed sign-in redirects back to the form, which then answers 401.
        if ($this->client->getResponse()->isRedirect('https://directory.test-aloha.social/console/login') || $this->client->getResponse()->isRedirect('/console/login')) {
            $this->client->followRedirect();
        }
    }

    protected function curator(string $email): Curator
    {
        $curator = $this->em->getRepository(Curator::class)->findOneBy(['email' => $email]) ?? throw new \LogicException('No curator '.$email);
        $this->em->refresh($curator);

        return $curator;
    }
}
