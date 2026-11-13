<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\LoadtestSeedCommand;
use App\Enum\ServerState;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class LoadtestTest extends FunctionalTestCase
{
    private const SEED = 'loadtest-seed-0123456789';
    private const SUFFIX = '.loadtest.directory.test-aloha.social';

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['LOADTEST_SEED'] = self::SEED;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LOADTEST_SEED']);
        parent::tearDown();
    }

    public function testSeedNeedsConfirmationAndCanBePurged(): void
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:loadtest:seed'));
        self::assertSame(2, $tester->execute(['--servers' => 3]));
        self::assertNull($this->em->getRepository(\App\Entity\Server::class)->findOneBy(['domain' => 'lt00001'.self::SUFFIX]));

        self::assertSame(0, $tester->execute(['--servers' => 3, '--not-production' => true]));
        $server = $this->server('lt00003'.self::SUFFIX);
        self::assertSame(ServerState::Listed, $server->state);
        [, $public] = LoadtestSeedCommand::keyPair(self::SEED, $server->domain);
        self::assertSame(rtrim(strtr(base64_encode($public), '+/', '-_'), '='), $server->publicKey);

        self::assertSame(0, $tester->execute(['--purge' => true, '--not-production' => true]));
        $this->em->clear();
        self::assertNull($this->em->getRepository(\App\Entity\Server::class)->findOneBy(['domain' => 'lt00003'.self::SUFFIX]));
    }

    /**
     * The k6 script signs with tools/loadtest/lib/signing.js; this checks it against the PHP verifier.
     */
    public function testLoadtestSigningMatchesTheDirectory(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if (null === $node) {
            self::markTestSkipped('Node.js is not installed.');
        }
        $tester = new CommandTester((new Application(static::$kernel))->find('app:loadtest:seed'));
        $tester->execute(['--servers' => 2, '--not-production' => true]);

        $domain = 'lt00002'.self::SUFFIX;
        $now = $this->clock->now();
        $body = (string) json_encode([
            'domain' => $domain,
            'software' => ['name' => 'aloha', 'version' => '0.27.0', 'nextcloud' => '33.0.2'],
            'signup' => ['open' => true, 'mode' => 'approval', 'available_accounts' => 100, 'approval_backlog' => 1],
            'usage' => ['users_total' => 1500, 'users_active_month' => 600],
            'search' => ['accounts' => false, 'hashtags' => false, 'statuses' => false, 'trends' => false, 'load' => 0],
            'health' => ['queue_lag_seconds' => 3, 'federation_ok' => true],
            'profile_etag' => 'W/"loadtest"',
            'sent_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ]);
        $path = "/api/v1/servers/{$domain}/heartbeat";
        $process = new Process([$node, 'tools/loadtest/sign.mjs', self::SEED, $domain, 'PUT', 'directory.test-aloha.social', $path, (string) $now->getTimestamp(), $body], static::getContainer()->getParameter('kernel.project_dir'));
        $process->mustRun();
        $signed = json_decode($process->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame($this->server($domain)->keyId, $signed['key_id']);

        $vars = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($signed['headers'] as $name => $value) {
            $vars['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->clock->sleep(600);
        $this->client->request('PUT', 'https://directory.test-aloha.social'.$path, [], [], $vars, $body);
        self::assertResponseStatusCodeSame(401, 'a signature older than 5 minutes is refused');

        $process = new Process([$node, 'tools/loadtest/sign.mjs', self::SEED, $domain, 'PUT', 'directory.test-aloha.social', $path, (string) $this->clock->now()->getTimestamp(), str_replace($now->format('Y-m-d\TH:i:s\Z'), $this->clock->now()->format('Y-m-d\TH:i:s\Z'), $body)], static::getContainer()->getParameter('kernel.project_dir'));
        $process->mustRun();
        $signed = json_decode($process->getOutput(), true, 8, \JSON_THROW_ON_ERROR);
        $vars = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($signed['headers'] as $name => $value) {
            $vars['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->client->request('PUT', 'https://directory.test-aloha.social'.$path, [], [], $vars, str_replace($now->format('Y-m-d\TH:i:s\Z'), $this->clock->now()->format('Y-m-d\TH:i:s\Z'), $body));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }
}
