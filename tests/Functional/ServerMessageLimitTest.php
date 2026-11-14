<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Curation\CurationService;
use App\Entity\Comment;
use App\Entity\Complaint;
use App\Tests\Support\FakeAlohaServer;
use App\Tests\Support\FunctionalTestCase;

/**
 * Messages from a server to the curators (comments and complaint responses) share one daily quota
 * per server (messages_per_server_day, 20 by default).
 */
final class ServerMessageLimitTest extends FunctionalTestCase
{
    public function testCommentsAndComplaintResponsesShareTheDailyQuota(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $complaintId = $this->sharedComplaint();

        for ($i = 1; $i <= 15; ++$i) {
            $this->message($fake, "Comment {$i}.");
        }
        for ($i = 1; $i <= 5; ++$i) {
            $this->api('POST', "/api/v1/servers/social.example.org/complaints/{$complaintId}/responses", ['body' => "Response {$i}."], $fake, 201);
            $this->clock->sleep(1);
        }

        // The 21st message of the day is refused, whichever endpoint it uses, and nothing is stored.
        $error = $this->message($fake, 'One more.', 429);
        self::assertSame('rate_limited', $error['error']);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->clock->sleep(1);
        $this->api('POST', "/api/v1/servers/social.example.org/complaints/{$complaintId}/responses", ['body' => 'One more.'], $fake, 429);
        self::assertCount(20, $this->em->getRepository(Comment::class)->findBy(['fromServer' => true]));
        self::assertCount(20, $this->em->getRepository(\App\Entity\AuditLogEntry::class)->findBy(['serverDomain' => 'social.example.org', 'action' => ['server.commented', 'complaint.responded']]));
    }

    public function testEachServerHasItsOwnQuota(): void
    {
        $first = $this->enrolServer('social.example.org');
        $second = $this->enrolServer('other.example.org');
        for ($i = 1; $i <= 20; ++$i) {
            $this->message($first, "Comment {$i}.");
        }
        $this->message($first, 'One more.', 429);

        // Another server is not affected by the first one's messages.
        $this->message($second, 'Hello curators.');
    }

    public function testRefusedRequestsDoNotCount(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $stranger = new FakeAlohaServer('social.example.org');

        // Unsigned, wrongly signed and invalid requests are rejected before the quota is counted,
        // so nobody else can use up a server's quota, and a buggy client does not burn it.
        for ($i = 0; $i < 25; ++$i) {
            $this->api('POST', '/api/v1/servers/social.example.org/comments', ['body' => 'Unsigned.'], null, 401);
            $this->api('POST', '/api/v1/servers/social.example.org/comments', ['body' => 'Forged.'], $stranger, 401);
            $this->message($fake, '', 422);
        }
        for ($i = 1; $i <= 20; ++$i) {
            $this->message($fake, "Comment {$i}.");
        }
        $this->message($fake, 'One more.', 429);
    }

    /**
     * @return array<mixed>
     */
    private function message(FakeAlohaServer $fake, string $body, int $expected = 201): array
    {
        $result = $this->api('POST', "/api/v1/servers/{$fake->domain}/comments", ['body' => $body], $fake, $expected);
        $this->clock->sleep(1); // a new signature for each request

        return $result;
    }

    private function sharedComplaint(): int
    {
        $result = $this->api('POST', '/api/v1/reports', ['domain' => 'social.example.org', 'category' => 'moderation', 'text' => 'My reports were ignored for three weeks.'], null, 202);
        $complaint = $this->em->find(Complaint::class, $result['id']);
        self::assertNotNull($complaint);
        static::getContainer()->get(CurationService::class)->shareComplaint($this->curator('alice@nextcloud.test'), $complaint);

        return (int) $result['id'];
    }
}
