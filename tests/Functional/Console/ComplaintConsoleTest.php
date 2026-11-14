<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Entity\Comment;
use App\Entity\Complaint;
use App\Enum\ComplaintStatus;
use App\Tests\Support\ConsoleBrowsing;
use App\Tests\Support\FunctionalTestCase;

/**
 * The complaint pages of the curator console, driven through the browser.
 */
final class ComplaintConsoleTest extends FunctionalTestCase
{
    use ConsoleBrowsing;

    private int $complaintId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listServer($this->enrolServer('social.example.org'));
        $result = $this->api('POST', '/api/v1/reports', [
            'domain' => 'social.example.org', 'category' => 'moderation',
            'text' => 'My reports about harassment were ignored for three weeks.', 'contact_email' => 'reporter@mail.example.org',
        ], null, 202);
        $this->complaintId = (int) $result['id'];
    }

    public function testCuratorSharesRepliesAndResolvesAComplaint(): void
    {
        $this->consoleLogin('bob@nextcloud.test');
        $id = $this->complaintId;

        $crawler = $this->page('/console/complaints');
        self::assertSelectorTextContains('table tbody', 'social.example.org');
        self::assertSelectorTextContains('table tbody', 'open');
        $crawler = $this->client->click($crawler->filter('table tbody a')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('dl.facts', 'reporter@mail.example.org');
        self::assertCount(0, $crawler->filter('form[action$="/reply"]'), 'a reply needs the complaint to be shared first');

        // Share: the admin is told, without the reporter's address.
        $this->submitForm($crawler, 'form[action$="/share"]');
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'admin@social.example.org');
        self::assertEmailTextBodyNotContains($mail, 'reporter@');
        $crawler = $this->followFlash('success', 'The admin can now read the complaint', "/console/complaints/{$id}");
        $complaint = $this->complaint();
        self::assertSame(ComplaintStatus::Shared, $complaint->status);
        self::assertNotNull($complaint->sharedAt);
        $entry = $this->lastAudit('complaint.shared', 'social.example.org');
        self::assertSame('bob@nextcloud.test', $entry->actorLabel);
        self::assertSame(['complaint' => $id], $entry->details);
        self::assertCount(0, $crawler->filter('form[action$="/share"]'));

        // Reply: the message lands in the thread the admin sees.
        $this->submitForm($crawler, 'form[action$="/reply"]', ['body' => 'Can you tell us how you handle reports?']);
        $crawler = $this->followFlash('success', 'Reply sent to the admin.');
        self::assertSelectorTextContains('article.comment', 'Can you tell us how you handle reports?');
        $comments = $this->em->getRepository(Comment::class)->findBy(['complaint' => $id]);
        self::assertCount(1, $comments);
        self::assertSame('bob@nextcloud.test', $comments[0]->curator?->email);
        self::assertFalse($comments[0]->fromServer);
        self::assertCount(1, $this->audit('complaint.replied', 'social.example.org'));
        $this->clock->sleep(1);
        $status = $this->api('GET', '/api/v1/servers/social.example.org/status', null, $this->fake('social.example.org'));
        self::assertSame([['from' => 'curators', 'body' => 'Can you tell us how you handle reports?']], array_map(
            static fn (array $r): array => ['from' => $r['from'], 'body' => $r['body']],
            $status['complaints'][0]['responses'],
        ));
        self::assertStringNotContainsString('Bob', (string) json_encode($status), 'the admin does not learn which curator wrote');

        // Resolve.
        $crawler = $this->page("/console/complaints/{$id}");
        $this->submitWithButton($crawler, 'Resolve', ['resolution' => 'The admin added two moderators.']);
        $crawler = $this->followFlash('success', 'Complaint resolved.');
        $complaint = $this->complaint();
        self::assertSame(ComplaintStatus::Resolved, $complaint->status);
        self::assertSame('The admin added two moderators.', $complaint->resolution);
        self::assertSame('bob@nextcloud.test', $complaint->closedBy?->email);
        self::assertNotNull($complaint->closedAt);
        self::assertCount(1, $this->audit('complaint.resolved', 'social.example.org'));
        self::assertCount(0, $crawler->filter('form[action*="/console/complaints/"]'), 'a closed complaint has no actions');
        self::assertSelectorTextContains('main', 'by Bob');

        // Closed complaints leave the open list but stay in "all".
        $this->page('/console/complaints');
        self::assertSelectorTextContains('table tbody', 'No complaints.');
        $this->page('/console/complaints?all=1');
        self::assertSelectorTextContains('table tbody', 'resolved');
    }

    public function testDismissNeedsAResolution(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $id = $this->complaintId;
        $crawler = $this->page("/console/complaints/{$id}");

        // Dismiss is a second button of the resolve form (formaction).
        $this->submitWithButton($crawler, 'Dismiss', ['resolution' => "  \n "]);
        $this->followFlash('error', 'Please give a resolution.', "/console/complaints/{$id}");
        $complaint = $this->complaint();
        self::assertSame(ComplaintStatus::Open, $complaint->status, 'a refused dismissal changes nothing');
        self::assertNull($complaint->closedAt);
        self::assertNull($complaint->closedBy);
        self::assertSame([], $this->audit('complaint.dismissed'));

        $crawler = $this->page("/console/complaints/{$id}");
        $this->submitWithButton($crawler, 'Dismiss', ['resolution' => 'Not about the server.']);
        $this->followFlash('success', 'Complaint dismissed.');
        $complaint = $this->complaint();
        self::assertSame(ComplaintStatus::Dismissed, $complaint->status);
        self::assertSame('Not about the server.', $complaint->resolution);
        self::assertSame('alice@nextcloud.test', $this->lastAudit('complaint.dismissed')->actorLabel);
        self::assertEmailCount(0);

        // Stale tabs: every action on a closed complaint is refused.
        foreach (['share' => 'Only open complaints can be shared.', 'resolve' => 'already closed', 'dismiss' => 'already closed'] as $action => $message) {
            $this->post("/console/complaints/{$id}/{$action}", ['resolution' => 'Again.']);
            $this->followFlash('error', $message);
        }
        self::assertSame(ComplaintStatus::Dismissed, $this->complaint()->status);
        self::assertSame('Not about the server.', $this->complaint()->resolution);
    }

    public function testReplyNeedsASharedComplaintAndABody(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $id = $this->complaintId;

        $this->post("/console/complaints/{$id}/reply", ['body' => 'Hello admin.']);
        $this->followFlash('error', 'Share the complaint with the admin first.');
        self::assertSame([], $this->em->getRepository(Comment::class)->findBy(['complaint' => $id]));

        $this->post("/console/complaints/{$id}/share");
        $this->followFlash('success', 'The admin can now read');
        $this->post("/console/complaints/{$id}/share");
        $this->followFlash('error', 'Only open complaints can be shared.');
        self::assertCount(1, $this->audit('complaint.shared'), 'sharing twice is refused');

        $this->post("/console/complaints/{$id}/reply", ['body' => '   ']);
        $this->followFlash('error', 'Please give a reply.');
        $this->post("/console/complaints/{$id}/reply", ['body' => str_repeat('x', 5001)]);
        $this->followFlash('error', 'at most 5000 characters');
        self::assertSame([], $this->em->getRepository(Comment::class)->findBy(['complaint' => $id]));
        self::assertSame([], $this->audit('complaint.replied'));

        // A shared complaint can still be resolved.
        $this->post("/console/complaints/{$id}/resolve", ['resolution' => 'Handled.']);
        $this->followFlash('success', 'Complaint resolved.');
        self::assertSame(ComplaintStatus::Resolved, $this->complaint()->status);
    }

    public function testFormsNeedCsrfAndKnownComplaints(): void
    {
        $id = $this->complaintId;

        // Not signed in: sent to the login page, nothing happens.
        $this->client->request('POST', self::$base."/console/complaints/{$id}/dismiss", ['resolution' => 'x']);
        self::assertResponseRedirects();
        self::assertStringContainsString('/console/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(ComplaintStatus::Open, $this->complaint()->status);

        $this->consoleLogin('alice@nextcloud.test');
        $this->client->request('POST', self::$base."/console/complaints/{$id}/dismiss", ['resolution' => 'Forged.', '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::$base."/console/complaints/{$id}/dismiss", ['resolution' => 'No token.']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ComplaintStatus::Open, $this->complaint()->status);

        $this->client->request('GET', self::$base.'/console/complaints/999');
        self::assertResponseStatusCodeSame(404);
        $this->post('/console/complaints/999/share');
        self::assertResponseStatusCodeSame(404);
        $this->post("/console/complaints/{$id}/delete");
        self::assertResponseStatusCodeSame(404);
        self::assertSame(ComplaintStatus::Open, $this->complaint()->status);
    }

    public function testCuratorWithAConflictOfInterestCannotCloseComplaintsAboutTheServer(): void
    {
        $this->consoleLogin('bob@nextcloud.test');
        $this->post('/console/servers/social.example.org/conflict', ['note' => 'I run this server.']);
        $this->followFlash('success', 'Conflict of interest recorded.');

        // Dismissing complaints about one's own server would also clear the "no unresolved
        // complaints" condition for featuring it.
        $this->post("/console/complaints/{$this->complaintId}/dismiss", ['resolution' => 'Nothing to see here.']);
        $this->followFlash('error', 'You declared a conflict of interest for this server.');
        self::assertSame(ComplaintStatus::Open, $this->complaint()->status);
        $this->post("/console/complaints/{$this->complaintId}/share");
        $this->followFlash('error', 'You declared a conflict of interest for this server.');
        self::assertSame(ComplaintStatus::Open, $this->complaint()->status);
    }

    private function complaint(): Complaint
    {
        $complaint = $this->em->find(Complaint::class, $this->complaintId);
        self::assertNotNull($complaint);
        $this->em->refresh($complaint);

        return $complaint;
    }
}
