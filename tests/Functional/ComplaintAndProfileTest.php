<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Curation\CurationService;
use App\Entity\Complaint;
use App\Tests\Support\Fixtures;
use App\Tests\Support\FunctionalTestCase;

final class ComplaintAndProfileTest extends FunctionalTestCase
{
    public function testComplaintReachesTheAdminWithoutTheReporter(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);

        // Complaints are only accepted for listed servers.
        $this->api('POST', '/api/v1/reports', ['domain' => 'unknown.example.org', 'category' => 'moderation', 'text' => str_repeat('Reports are ignored. ', 3)], null, 422);
        $result = $this->api('POST', '/api/v1/reports', [
            'domain' => 'social.example.org', 'category' => 'moderation',
            'text' => 'My reports about harassment were ignored for three weeks.', 'contact_email' => 'reporter@mail.example.org',
        ], null, 202);
        $complaint = $this->em->find(Complaint::class, $result['id']);
        self::assertNotNull($complaint);

        // Not visible to the admin until a curator shares it.
        self::assertSame([], $this->api('GET', '/api/v1/servers/social.example.org/status', null, $fake)['complaints']);

        $complaint = $this->em->find(Complaint::class, $result['id']);
        static::getContainer()->get(CurationService::class)->shareComplaint($this->curator('alice@nextcloud.test'), $complaint);
        $this->clock->sleep(1);
        $status = $this->api('GET', '/api/v1/servers/social.example.org/status', null, $fake);
        self::assertCount(1, $status['complaints']);
        self::assertSame('My reports about harassment were ignored for three weeks.', $status['complaints'][0]['body']);
        self::assertStringNotContainsString('reporter@', (string) json_encode($status));

        $this->clock->sleep(1);
        $this->api('POST', "/api/v1/servers/social.example.org/complaints/{$result['id']}/responses", ['body' => 'We are looking into it.'], $fake, 201);
        $this->clock->sleep(1);
        $status = $this->api('GET', '/api/v1/servers/social.example.org/status', null, $fake);
        self::assertSame('We are looking into it.', $status['complaints'][0]['responses'][0]['body']);
    }

    public function testReviewedProfileChangesWaitForACurator(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);

        $profile = Fixtures::profile('social.example.org');
        $profile['identity']['description'] = 'Now with more cats.';
        $profile['moderation']['moderators'] = 1;
        $profile['etag'] = 'W/"8"';
        $this->clock->sleep(1);
        self::assertTrue($this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake)['pending_review']);

        $server = $this->server('social.example.org');
        self::assertSame('Now with more cats.', $server->profile['identity']['description'], 'descriptions go live at once');
        self::assertSame(4, $server->profile['moderation']['moderators'], 'moderation keeps the approved value');
        self::assertSame(1, $server->pendingProfile['moderation']['moderators']);

        $seen = (string) $server->pendingProfileFingerprint();

        // Newer changes after the curator looked: approving what they saw must fail.
        $newer = $profile;
        $newer['operator']['name'] = 'Someone Else Ltd';
        $newer['etag'] = 'W/"9"';
        $this->clock->sleep(1);
        $this->api('PUT', '/api/v1/servers/social.example.org/profile', $newer, $fake);
        try {
            static::getContainer()->get(CurationService::class)->approveProfileChanges($this->curator('bob@nextcloud.test'), $this->server('social.example.org'), $seen);
            self::fail('Approving changes the curator did not see must fail.');
        } catch (\App\Curation\CurationException) {
        }
        $this->clock->sleep(1);
        $this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake);
        $server = $this->server('social.example.org');
        self::assertSame($seen, $server->pendingProfileFingerprint());

        static::getContainer()->get(CurationService::class)->approveProfileChanges($this->curator('bob@nextcloud.test'), $server, $seen);
        $server = $this->server('social.example.org');
        self::assertSame(1, $server->profile['moderation']['moderators']);
        self::assertNull($server->pendingProfile);

        // Approval covers the whole profile; later live changes can be reverted to it.
        $profile['identity']['description'] = 'Totally misleading text.';
        $this->clock->sleep(1);
        self::assertFalse($this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake)['pending_review']);
        self::assertSame('Totally misleading text.', $this->server('social.example.org')->profile['identity']['description']);
        static::getContainer()->get(CurationService::class)->revertLiveChanges($this->curator('bob@nextcloud.test'), $this->server('social.example.org'), 'Misleading description.');
        self::assertSame('Now with more cats.', $this->server('social.example.org')->profile['identity']['description']);
    }

    public function testHeartbeatWithNewEtagRequestsTheProfile(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $hb = Fixtures::heartbeat('social.example.org', $this->clock->now());
        $hb['profile_etag'] = 'W/"changed"';
        $response = $this->api('PUT', '/api/v1/servers/social.example.org/heartbeat', $hb, $fake);
        self::assertTrue($response['profile_requested']);
    }

    public function testReportPageWorks(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $crawler = $this->client->request('GET', 'https://directory.test-aloha.social/report?domain=social.example.org');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $this->client->submit($form, ['category' => 'privacy', 'text' => 'They sell user data to advertisers, see their FAQ.']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->em->getRepository(Complaint::class)->findAll());

        // Without a valid CSRF token nothing is stored.
        $this->client->request('POST', 'https://directory.test-aloha.social/report', ['domain' => 'social.example.org', 'category' => 'privacy', 'text' => str_repeat('x', 30), '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(Complaint::class)->findAll());
    }
}
