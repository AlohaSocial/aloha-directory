<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServerCheck;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class BackgroundJobsTest extends FunctionalTestCase
{
    public function testVerificationIsQueuedOnceAndPolled(): void
    {
        $fake = $this->fake('social.example.org');
        $result = $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.example.org', 'public_key' => $fake->publicKey()], null, 201);
        $fake->challenge = $result['challenge'];

        $this->api('POST', '/api/v1/servers/social.example.org/verify', null, $fake, 202);
        $this->clock->sleep(1);
        $this->api('POST', '/api/v1/servers/social.example.org/verify', null, $fake, 202);
        $this->clock->sleep(1);
        self::assertSame('queued', $this->api('GET', '/api/v1/servers/social.example.org/verify', null, $fake)['status']);
        self::assertNull($this->em->getRepository(\App\Entity\Server::class)->findOneBy(['domain' => 'social.example.org']), 'nothing happens in the web request');

        self::assertSame(1, $this->runJobs(), 'a second request while queued adds no job');
        $this->clock->sleep(1);
        $status = $this->api('GET', '/api/v1/servers/social.example.org/verify', null, $fake);
        self::assertSame('verified', $status['status']);
        self::assertSame('applied', $status['state']);
    }

    public function testStrangersCannotReadTheStatus(): void
    {
        $fake = $this->fake('social.example.org');
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.example.org', 'public_key' => $fake->publicKey()], null, 201);
        $other = $this->fake('other.example.org');
        $this->api('GET', '/api/v1/servers/social.example.org/verify', null, $other, 401);
    }

    public function testHourlyChecksAreQueuedPerServer(): void
    {
        $this->enrolServer('a.example.org');
        $this->enrolServer('b.example.org');
        $this->runJobs();

        $tester = new CommandTester((new Application(static::$kernel))->find('app:check'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $tester->execute([]), 'a second run while jobs wait queues nothing');
        self::assertSame([], $this->em->getRepository(ServerCheck::class)->findAll(), 'the command only queues');
        self::assertSame(2, $this->runJobs());
        self::assertNotEmpty($this->em->getRepository(ServerCheck::class)->findAll());
    }
}
