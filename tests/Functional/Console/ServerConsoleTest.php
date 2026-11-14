<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Curation\Covenant;
use App\Entity\Comment;
use App\Entity\Heartbeat;
use App\Entity\Review;
use App\Entity\TransparencyEntry;
use App\Enum\CommentVisibility;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use App\Tests\Support\ConsoleBrowsing;
use App\Tests\Support\FakeAlohaServer;
use App\Tests\Support\Fixtures;
use App\Tests\Support\FunctionalTestCase;

/**
 * The server page of the curator console: reviews, decisions (one curator decides), pauses, the
 * waiting list, comments, profile decisions and conflicts of interest, all through the real forms.
 */
final class ServerConsoleTest extends FunctionalTestCase
{
    use ConsoleBrowsing;

    private const DOMAIN = 'social.example.org';
    private const PAGE = '/console/servers/social.example.org';

    public function testApplicationIsReviewedAndListedByOneCurator(): void
    {
        $this->bringToReview($this->enrolServer(self::DOMAIN));
        $this->createCurator('alice@nextcloud.test', true);
        $this->createCurator('bob@nextcloud.test');

        $this->signInAs('alice@nextcloud.test');
        $crawler = $this->page(self::PAGE);
        $this->submitForm($crawler, 'form[action$="/review"]', $this->allChecked(Covenant::INITIAL_CHECKLIST) + ['notes' => 'Talked to the operator.']);
        $crawler = $this->followFlash('success', 'Review recorded.', self::PAGE);
        $review = $this->em->getRepository(Review::class)->findOneBy([]);
        self::assertNotNull($review);
        self::assertSame(Review::KIND_INITIAL, $review->kind);
        self::assertSame(array_fill_keys(array_keys(Covenant::INITIAL_CHECKLIST), true), $review->checklist);
        self::assertSame($this->server(self::DOMAIN)->profileFingerprint(), $review->profileFingerprint);
        self::assertTrue($this->lastAudit('review.recorded', self::DOMAIN)->details['passed']);
        self::assertStringContainsString('Talked to the operator.', $crawler->filter('article.review')->text());
        self::assertSelectorTextContains('main', 'Make a decision (takes effect at once)');

        // Alice's decision takes effect at once: no second curator is involved.
        $this->submitForm($crawler, 'form[action$="/decide"]', ['decision' => 'list', 'reason' => 'Adds a German-speaking server.']);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'admin@social.example.org');
        self::assertEmailSubjectContains($mail, 'Your server is listed');
        $crawler = $this->followFlash('success', 'Done: "List". The published list updates within a minute.', self::PAGE);

        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::Listed, $server->state);
        self::assertNotNull($server->listedAt);
        $entry = $this->lastAudit('server.listed', self::DOMAIN);
        self::assertSame('alice@nextcloud.test', $entry->actorLabel);
        self::assertSame(['reason' => 'Adds a German-speaking server.'], $entry->details);
        self::assertSelectorTextContains('main', 'As published in servers.json');

        // A second click (e.g. a stale tab) changes nothing.
        $this->post(self::PAGE.'/decide', ['decision' => 'list', 'reason' => 'Again.']);
        $this->followFlash('error', '"List" does not apply to social.example.org in its current state (Listed).');
        self::assertCount(1, $this->audit('server.listed'));
    }

    public function testListingNeedsACompleteReviewOfTheCurrentProfile(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->bringToReview($fake);
        $this->createCurator('alice@nextcloud.test', true);
        $this->createCurator('bob@nextcloud.test');
        $this->consoleLogin('alice@nextcloud.test');

        $this->decide(['decision' => 'list', 'reason' => 'Looks fine.'], 'Complete the review checklist (all items) before listing this server.', 'error');

        // An incomplete checklist is recorded, but does not allow a listing.
        $checked = $this->allChecked(Covenant::INITIAL_CHECKLIST);
        unset($checked['checklist[mix]']);
        $this->submitForm($this->page(self::PAGE), 'form[action$="/review"]', $checked + ['notes' => 'Mix unclear.']);
        $this->followFlash('success', 'Review recorded.');
        self::assertFalse($this->lastAudit('review.recorded')->details['passed']);
        self::assertFalse($this->lastAudit('review.recorded')->details['checklist']['mix']);
        $this->decide(['decision' => 'list', 'reason' => 'Looks fine.'], 'Complete the review checklist (all items)', 'error');

        // The profile changes while the curator fills in the checklist.
        $crawler = $this->page(self::PAGE);
        $this->changeProfile($fake, static function (array &$p): void {
            $p['operator']['name'] = 'Someone Else Ltd';
        });
        $this->submitForm($crawler, 'form[action$="/review"]', $this->allChecked(Covenant::INITIAL_CHECKLIST) + ['notes' => 'All good.']);
        $this->followFlash('error', 'changed its terms, jurisdiction, operator or moderation details while you were reviewing');
        self::assertCount(1, $this->em->getRepository(Review::class)->findAll());

        // Notes are required.
        $this->submitForm($this->page(self::PAGE), 'form[action$="/review"]', $this->allChecked(Covenant::INITIAL_CHECKLIST) + ['notes' => ' ']);
        $this->followFlash('error', 'Please give a notes.');

        // Re-reviews are for listed servers only.
        $fingerprint = $this->server(self::DOMAIN)->profileFingerprint();
        $this->post(self::PAGE.'/review', ['kind' => 'rereview', 'notes' => 'x', 'checklist' => ['test_report' => '1'], 'fingerprint' => $fingerprint]);
        $this->followFlash('error', 'Re-reviews are for listed servers.');
        // A review without the fingerprint of the profile the curator saw is refused.
        $this->post(self::PAGE.'/review', ['kind' => 'initial', 'notes' => 'x', 'checklist' => ['mix' => '1']]);
        $this->followFlash('error', 'while you were reviewing');
        self::assertCount(1, $this->em->getRepository(Review::class)->findAll());

        // A complete review of the current profile allows the listing.
        $this->submitForm($this->page(self::PAGE), 'form[action$="/review"]', $this->allChecked(Covenant::INITIAL_CHECKLIST) + ['notes' => 'All good.']);
        $this->followFlash('success', 'Review recorded.');

        // Another curator's review does not count for Bob.
        $alice = $this->saveSession();
        $this->signInAs('bob@nextcloud.test');
        $this->decide(['decision' => 'list', 'reason' => 'Alice checked it.'], 'Complete the review checklist (all items)', 'error');
        self::assertSame(ServerState::InReview, $this->server(self::DOMAIN)->state);

        $this->restoreSession($alice);
        $this->decide(['decision' => 'list', 'reason' => 'Looks fine.'], 'Done: "List".');
        self::assertSame(ServerState::Listed, $this->server(self::DOMAIN)->state);
        self::assertSame('Someone Else Ltd', $this->server(self::DOMAIN)->approvedProfile['operator']['name']);
    }

    public function testDecisionInputIsValidated(): void
    {
        $this->listServer($this->enrolServer(self::DOMAIN));
        $this->consoleLogin('alice@nextcloud.test');
        $before = \count($this->audit());

        $cases = [
            'Please give a reason.' => ['decision' => 'weight', 'weight' => '1.5', 'reason' => "\n "],
            'The weight must be a number between 0.5 and 2.' => ['decision' => 'weight', 'weight' => 'heavy', 'reason' => 'r'],
            'The weight must be between 0.5 and 2, in steps of 0.05.' => ['decision' => 'weight', 'weight' => '2.5', 'reason' => 'r'],
            'in steps of 0.05' => ['decision' => 'weight', 'weight' => '1.03', 'reason' => 'r'],
            '"Change weight" does not apply to social.example.org in its current state (Listed).' => ['decision' => 'weight', 'weight' => '1', 'reason' => 'r'],
            'Choose the rule that was broken.' => ['decision' => 'delist', 'rule' => '', 'public_reason' => 'Bad.', 'reason' => 'r'],
            'Please give a public reason.' => ['decision' => 'delist', 'rule' => 'backups', 'public_reason' => '', 'reason' => 'r'],
            '"Reject application" does not apply' => ['decision' => 'reject', 'public_reason' => 'No.', 'reason' => 'r'],
            '"List" does not apply' => ['decision' => 'list', 'reason' => 'r'],
            '"Remove featured status" does not apply' => ['decision' => 'unfeature', 'reason' => 'r'],
            '"Remove as search provider" does not apply' => ['decision' => 'disable_search', 'reason' => 'r'],
        ];
        foreach ($cases as $message => $values) {
            $this->decide($values, $message, 'error');
        }

        // Unknown decisions and servers, and forged forms.
        $this->post(self::PAGE.'/decide', ['decision' => 'explode', 'reason' => 'r']);
        self::assertResponseStatusCodeSame(404);
        $this->post('/console/servers/unknown.example.org/decide', ['decision' => 'weight', 'weight' => '1.5', 'reason' => 'r']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::$base.self::PAGE.'/decide', ['decision' => 'weight', 'weight' => '1.5', 'reason' => 'r', '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        self::assertCount($before, $this->audit(), 'no refused decision is recorded');
        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::Listed, $server->state);
        self::assertSame(100, $server->weightPercent);
    }

    public function testWeightSearchFeatureAndDelistDecisions(): void
    {
        $this->listServer($this->enrolServer(self::DOMAIN));
        $this->signInAs('alice@nextcloud.test');
        $this->decide(['decision' => 'weight', 'weight' => '1.5', 'reason' => 'Strong moderation team.'], 'Done: "Change weight".');
        self::assertSame(150, $this->server(self::DOMAIN)->weightPercent);
        self::assertSame(['from' => 1, 'to' => 1.5, 'reason' => 'Strong moderation team.'], $this->lastAudit('server.weight_changed', self::DOMAIN)->details);

        // Any curator decides, not only the one who listed the server.
        $this->signInAs('bob@nextcloud.test');
        $this->decide(['decision' => 'enable_search', 'reason' => 'Fast search.'], 'Done: "Accept as search provider".');
        self::assertTrue($this->server(self::DOMAIN)->searchProvider);
        self::assertSame('bob@nextcloud.test', $this->lastAudit('server.search_enabled', self::DOMAIN)->actorLabel);

        // A new server is not eligible for featuring yet.
        $this->decide(['decision' => 'feature', 'reason' => 'Great server.'], 'The server cannot be featured yet: Listed for less than', 'error');
        self::assertFalse($this->server(self::DOMAIN)->featured);
        self::assertSame([], $this->audit('server.featured'));

        $this->decide(['decision' => 'disable_search', 'reason' => 'Logs too long.'], 'Done: "Remove as search provider".');
        self::assertFalse($this->server(self::DOMAIN)->searchProvider);
        self::assertSame(['reason' => 'Logs too long.'], $this->lastAudit('server.search_disabled', self::DOMAIN)->details);

        $this->decide(['decision' => 'delist', 'rule' => 'active_moderation', 'public_reason' => 'Reports went unanswered.', 'reason' => 'Complaint #1.'], 'Done: "Delist".');
        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::Delisted, $server->state);
        self::assertSame('Reports went unanswered.', $server->decisionReason);
        $entries = $this->em->getRepository(TransparencyEntry::class)->findBy(['serverDomain' => self::DOMAIN]);
        self::assertCount(1, $entries);
        self::assertSame(['rule' => 'active_moderation', 'public_reason' => 'Reports went unanswered.', 'reason' => 'Complaint #1.'], $this->lastAudit('server.delisted', self::DOMAIN)->details);
        self::assertSelectorTextContains('header.page-head', 'Public reason: Reports went unanswered.');
    }

    public function testEstablishedServerIsFeaturedOnceItsComplaintsAreClosed(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->listServer($fake);
        // 91 days later, with a heartbeat every hour of the last 30 days.
        $this->clock->sleep(91 * 86400);
        $server = $this->server(self::DOMAIN);
        $now = $this->clock->now();
        for ($h = 720; $h >= 1; --$h) {
            $at = $now->modify("-{$h} hours");
            $this->em->persist(new Heartbeat($server, $at, $at, true, 400, 3, 1500, 900, '0.27.0', true, 10, 0.2));
        }
        $this->em->flush();
        $this->heartbeat($fake);
        $complaint = $this->api('POST', '/api/v1/reports', ['domain' => self::DOMAIN, 'category' => 'moderation', 'text' => 'My reports were ignored for weeks.'], null, 202);

        // Not while the complaint is open.
        $this->signInAs('alice@nextcloud.test');
        $this->page(self::PAGE);
        self::assertSelectorTextContains('p.hint', 'Not yet eligible for featuring: 1 unresolved complaint(s).');
        $this->decide(['decision' => 'feature', 'reason' => 'Reliable for three months.'], 'The server cannot be featured yet: 1 unresolved complaint(s).', 'error');
        self::assertFalse($this->server(self::DOMAIN)->featured);

        $this->post("/console/complaints/{$complaint['id']}/resolve", ['resolution' => 'The admin answered the reporter.']);
        $this->followFlash('success', 'Complaint resolved.');
        $crawler = $this->page(self::PAGE);
        self::assertStringNotContainsString('Not yet eligible for featuring', $crawler->filter('main')->text());
        $this->decide(['decision' => 'feature', 'reason' => 'Reliable for three months.'], 'Done: "Feature".');
        $server = $this->server(self::DOMAIN);
        self::assertTrue($server->featured);
        self::assertEquals($this->clock->now(), $server->featuredAt);
        self::assertCount(1, $this->audit('server.featured', self::DOMAIN));
        self::assertSelectorTextContains('header.page-head', 'Featured');

        // Featured status is removed the same way.
        $this->decide(['decision' => 'feature', 'reason' => 'Again.'], '"Feature" does not apply', 'error');
        $this->signInAs('bob@nextcloud.test');
        $this->decide(['decision' => 'unfeature', 'reason' => 'Too many newcomers.'], 'Done: "Remove featured status".');
        $server = $this->server(self::DOMAIN);
        self::assertFalse($server->featured);
        self::assertNull($server->featuredAt);
        self::assertSame(['reason' => 'Too many newcomers.'], $this->lastAudit('server.unfeatured', self::DOMAIN)->details);
    }

    public function testApplicationIsRejected(): void
    {
        $this->bringToReview($this->enrolServer(self::DOMAIN));
        $this->createCurator('alice@nextcloud.test', true);
        $this->signInAs('alice@nextcloud.test');

        $this->decide(['decision' => 'reject', 'public_reason' => '', 'reason' => 'Fake operator.'], 'Please give a reason for the admin.', 'error');
        self::assertSame(ServerState::InReview, $this->server(self::DOMAIN)->state);

        // No review is needed to reject.
        $this->submitForm($this->page(self::PAGE), 'form[action$="/decide"]', ['decision' => 'reject', 'public_reason' => 'The operator could not be verified.', 'reason' => 'Fake operator.']);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailSubjectContains($mail, 'Your application was not accepted');
        $this->followFlash('success', 'Done: "Reject application".', self::PAGE);
        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::Rejected, $server->state);
        self::assertSame('The operator could not be verified.', $server->decisionReason);
        $entry = $this->lastAudit('server.rejected', self::DOMAIN);
        self::assertSame('alice@nextcloud.test', $entry->actorLabel);
        self::assertSame(['public_reason' => 'The operator could not be verified.', 'reason' => 'Fake operator.'], $entry->details);

        $this->decide(['decision' => 'reject', 'public_reason' => 'Again.', 'reason' => 'Again.'], '"Reject application" does not apply', 'error');
        self::assertCount(1, $this->audit('server.rejected'));
    }

    public function testConflictOfInterestBlocksEveryDecision(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->listServer($fake);

        $this->signInAs('bob@nextcloud.test');
        $this->submitForm($this->page(self::PAGE), 'form[action$="/conflict"]', ['note' => 'I am a moderator there.']);
        $crawler = $this->followFlash('success', 'Conflict of interest recorded. You will not review this server.', self::PAGE);
        self::assertSelectorTextContains('header [role="alert"]', 'You declared a conflict of interest for this server (I am a moderator there.)');
        self::assertSelectorTextContains('header [role="alert"]', 'You can read, but not review or decide.');
        self::assertCount(0, $crawler->filter('form[action^="/console/servers/"]'), 'no action forms for a conflicted curator');
        self::assertSame(['curator' => 'bob@nextcloud.test', 'note' => 'I am a moderator there.'], $this->lastAudit('conflict.declared', self::DOMAIN)->details);

        // Declaring it again is harmless.
        $this->post(self::PAGE.'/conflict', ['note' => 'Again.']);
        $this->followFlash('success', 'Conflict of interest recorded.');
        self::assertCount(1, $this->audit('conflict.declared'));

        $this->changeProfile($fake, static function (array &$p): void {
            $p['moderation']['moderators'] = 1;
            $p['identity']['description'] = 'Changed live.';
        });
        $fingerprint = (string) $this->server(self::DOMAIN)->pendingProfileFingerprint();
        $message = 'You declared a conflict of interest for this server.';
        foreach ([
            ['/decide', ['decision' => 'weight', 'weight' => '0.5', 'reason' => 'x']],
            ['/decide', ['decision' => 'delist', 'rule' => 'backups', 'public_reason' => 'x', 'reason' => 'x']],
            ['/pause', ['concern' => 'x']],
            ['/review', ['kind' => 'rereview', 'notes' => 'x', 'checklist' => ['test_report' => '1']]],
            ['/comment', ['body' => 'x', 'visibility' => 'internal']],
            ['/profile/approve', ['fingerprint' => $fingerprint]],
            ['/profile/reject', ['reason' => 'x']],
            ['/profile/revert', ['reason' => 'x']],
        ] as [$path, $fields]) {
            $this->post(self::PAGE.$path, $fields);
            $this->followFlash('error', $message, self::PAGE);
        }
        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::Listed, $server->state);
        self::assertSame(100, $server->weightPercent);
        self::assertNotNull($server->pendingProfile);
        self::assertSame('Changed live.', $server->profile['identity']['description']);
        self::assertSame([], array_filter($this->audit(null, self::DOMAIN), static fn ($e): bool => 'bob@nextcloud.test' === $e->actorLabel && 'conflict.declared' !== $e->action));
    }

    public function testCuratorPausesAndAnotherCuratorResumes(): void
    {
        $this->listServer($this->enrolServer(self::DOMAIN));
        $this->signInAs('alice@nextcloud.test');

        $this->submitForm($this->page(self::PAGE), 'form[action$="/pause"]', ['concern' => ' ']);
        $this->followFlash('error', 'Please give a concern.');
        self::assertSame(ServerState::Listed, $this->server(self::DOMAIN)->state);

        $this->submitForm($this->page(self::PAGE), 'form[action$="/pause"]', ['concern' => 'Investigating a data breach report.']);
        self::assertEmailCount(1);
        $crawler = $this->followFlash('success', 'The server is paused and leaves the list within a minute. The admin was told.', self::PAGE);
        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::Paused, $server->state);
        self::assertSame(PauseKind::Curator, $server->pauseKind);
        self::assertSame('Investigating a data breach report.', $server->pauseReason);
        self::assertSelectorTextContains('header .flash-warning', 'Paused by a curator: Investigating a data breach report.');
        self::assertCount(0, $crawler->filter('form[action$="/pause"]'), 'a curator pause cannot be repeated');
        self::assertNotEmpty($this->audit(null, self::DOMAIN));

        $this->post(self::PAGE.'/pause', ['concern' => 'Again.']);
        $this->followFlash('error', 'Only listed or automatically paused servers can be paused by a curator.');

        // One curator resumes it (no new review for a paused server), here not the one who paused it.
        $this->signInAs('bob@nextcloud.test');
        $this->decide(['decision' => 'list', 'reason' => 'The report was unfounded.'], 'Done: "List".');
        self::assertSame(ServerState::Listed, $this->server(self::DOMAIN)->state);
        self::assertNull($this->server(self::DOMAIN)->pauseKind);
        $entry = $this->lastAudit('server.resumed', self::DOMAIN);
        self::assertSame('bob@nextcloud.test', $entry->actorLabel);
        self::assertSame(['reason' => 'The report was unfounded.'], $entry->details);
    }

    public function testCuratorPauseReplacesAnAutomaticPause(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer(self::DOMAIN);
        $this->listServer($fake);
        $this->clock->sleep(8 * 86400);
        $this->tick();
        self::assertSame(PauseKind::Stale, $this->server(self::DOMAIN)->pauseKind);

        $this->consoleLogin('alice@nextcloud.test');
        $this->submitForm($this->page(self::PAGE), 'form[action$="/pause"]', ['concern' => 'The operator stopped answering.']);
        $this->followFlash('success', 'The server is paused');
        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::Paused, $server->state);
        self::assertSame(PauseKind::Curator, $server->pauseKind);

        // The server comes back, but stays paused until a curator resumes it.
        $this->heartbeat($fake);
        $this->tick();
        self::assertSame(ServerState::Paused, $this->server(self::DOMAIN)->state);
        self::assertSame(PauseKind::Curator, $this->server(self::DOMAIN)->pauseKind);
    }

    public function testApplicationGoesOnTheWaitingList(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->bringToReview($fake);
        $this->createCurator('alice@nextcloud.test');
        $this->consoleLogin('alice@nextcloud.test');

        $this->submitForm($this->page(self::PAGE), 'form[action$="/waitlist"]', ['note' => '']);
        $this->followFlash('error', 'Please give a note.');
        self::assertSame(ServerState::InReview, $this->server(self::DOMAIN)->state);

        $this->submitForm($this->page(self::PAGE), 'form[action$="/waitlist"]', ['note' => 'We have enough German servers right now.']);
        $crawler = $this->followFlash('success', 'The application is on the waiting list.', self::PAGE);
        self::assertSame(ServerState::Waitlisted, $this->server(self::DOMAIN)->state);
        self::assertSame(['note' => 'We have enough German servers right now.'], $this->lastAudit('server.waitlisted', self::DOMAIN)->details);
        self::assertCount(0, $crawler->filter('form[action$="/waitlist"]'));
        $this->clock->sleep(1);
        $status = $this->api('GET', '/api/v1/servers/social.example.org/status', null, $fake);
        self::assertSame('Your application is on the waiting list. We have enough German servers right now.', $status['comments'][0]['body']);

        $this->post(self::PAGE.'/waitlist', ['note' => 'Again.']);
        $this->followFlash('error', 'Only applications in review can be put on the waiting list.');
        self::assertCount(1, $this->audit('server.waitlisted'));
    }

    public function testNotesStayInternalAndQuestionsReachTheAdmin(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->createCurator('alice@nextcloud.test');
        $this->consoleLogin('alice@nextcloud.test');

        $this->submitForm($this->page(self::PAGE), 'form[action$="/comment"]', ['body' => 'Operator seems legit.']);
        self::assertEmailCount(0);
        $this->followFlash('success', 'Note saved.', self::PAGE);
        self::assertSelectorTextContains('article.comment.internal', 'Operator seems legit.');

        $this->submitForm($this->page(self::PAGE), 'form[action$="/comment"]', ['body' => 'How many moderators do you have?', 'visibility' => 'admin']);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'admin@social.example.org');
        self::assertEmailTextBodyContains($mail, 'How many moderators do you have?');
        $this->followFlash('success', 'The question was sent to the server admin.');
        self::assertSelectorTextContains('article.comment.to-admin', 'How many moderators do you have?');

        $this->submitForm($this->page(self::PAGE), 'form[action$="/comment"]', ['body' => "  \n"]);
        $this->followFlash('error', 'Please give a comment.');

        $comments = $this->em->getRepository(Comment::class)->findBy(['server' => $this->server(self::DOMAIN)->id], ['id' => 'ASC']);
        self::assertCount(2, $comments);
        self::assertSame(CommentVisibility::Internal, $comments[0]->visibility);
        self::assertSame(CommentVisibility::Admin, $comments[1]->visibility);
        self::assertCount(1, $this->audit('server.note_added', self::DOMAIN));
        self::assertCount(1, $this->audit('server.question_sent', self::DOMAIN));

        // Only the question is visible to the server.
        $this->clock->sleep(1);
        $status = $this->api('GET', '/api/v1/servers/social.example.org/status', null, $fake);
        self::assertSame(['How many moderators do you have?'], array_column($status['comments'], 'body'));
    }

    public function testReviewedProfileChangesAreAcceptedOrRejected(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->listServer($fake);
        $this->consoleLogin('bob@nextcloud.test');

        $this->post(self::PAGE.'/profile/approve', ['fingerprint' => str_repeat('0', 64)]);
        $this->followFlash('error', 'There are no profile changes waiting.');
        $this->post(self::PAGE.'/profile/reject', ['reason' => 'x']);
        $this->followFlash('error', 'There are no profile changes waiting.');

        $this->changeProfile($fake, static function (array &$p): void {
            $p['moderation']['moderators'] = 1;
        });
        $crawler = $this->page(self::PAGE);
        self::assertSelectorTextContains('section.highlight', 'Profile changes waiting for review');

        // Rejecting needs a reason for the admin.
        $this->submitForm($crawler, 'form[action$="/profile/reject"]', ['reason' => '']);
        $this->followFlash('error', 'Please give a reason.');
        $this->submitForm($this->page(self::PAGE), 'form[action$="/profile/reject"]', ['reason' => 'One moderator is not enough for 900 users.']);
        $this->followFlash('success', 'Profile changes rejected; the admin was told why.', self::PAGE);
        $server = $this->server(self::DOMAIN);
        self::assertNull($server->pendingProfile);
        self::assertSame(4, $server->profile['moderation']['moderators']);
        self::assertSame(['reason' => 'One moderator is not enough for 900 users.'], $this->lastAudit('profile.changes_rejected', self::DOMAIN)->details);
        $this->clock->sleep(1);
        $status = $this->api('GET', '/api/v1/servers/social.example.org/status', null, $fake);
        self::assertSame('The curators did not accept your profile changes. One moderator is not enough for 900 users.', $status['comments'][0]['body']);

        // The admin sends a change; while the curator looks, a newer one arrives.
        $this->changeProfile($fake, static function (array &$p): void {
            $p['moderation']['moderators'] = 6;
        });
        $crawler = $this->page(self::PAGE);
        $this->changeProfile($fake, static function (array &$p): void {
            $p['moderation']['moderators'] = 6;
            $p['operator']['name'] = 'Someone Else Ltd';
        });
        $this->submitForm($crawler, 'form[action$="/profile/approve"]');
        $this->followFlash('error', 'The server sent newer profile changes in the meantime.');
        self::assertSame('Example e.V.', $this->server(self::DOMAIN)->profile['operator']['name']);

        $this->submitForm($this->page(self::PAGE), 'form[action$="/profile/approve"]');
        $this->followFlash('success', 'Profile changes accepted.', self::PAGE);
        $server = $this->server(self::DOMAIN);
        self::assertNull($server->pendingProfile);
        self::assertSame('Someone Else Ltd', $server->profile['operator']['name']);
        self::assertSame('Someone Else Ltd', $server->approvedProfile['operator']['name']);
        self::assertSame(6, $server->profile['moderation']['moderators']);
        self::assertCount(1, $this->audit('profile.changes_approved', self::DOMAIN));

        // Unknown decisions.
        $this->post(self::PAGE.'/profile/ignore', ['reason' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLiveChangesAreRevertedToTheApprovedProfile(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->listServer($fake);
        $this->enrolServer('applied.example.org');
        $this->consoleLogin('bob@nextcloud.test');

        // No approved profile yet for an application.
        $this->post('/console/servers/applied.example.org/profile/revert', ['reason' => 'x']);
        $this->followFlash('error', 'There is no approved profile to revert to.');

        $this->changeProfile($fake, static function (array &$p): void {
            $p['identity']['description'] = 'Totally misleading text.';
            $p['identity']['logo_url'] = 'https://social.example.org/other-logo.png';
            $p['identity']['banner_url'] = 'https://social.example.org/banner.png';
        });
        $crawler = $this->page(self::PAGE);
        self::assertSelectorTextContains('main', 'Changes since the approved version');
        self::assertSelectorTextContains('main', 'Totally misleading text.');

        $this->submitForm($crawler, 'form[action$="/profile/revert"]', ['reason' => '']);
        $this->followFlash('error', 'Please give a reason.');
        self::assertSame('Totally misleading text.', $this->server(self::DOMAIN)->profile['identity']['description']);

        $this->submitForm($this->page(self::PAGE), 'form[action$="/profile/revert"]', ['reason' => 'Misleading description.']);
        $crawler = $this->followFlash('success', 'Descriptions and images reverted to the approved version.', self::PAGE);
        $server = $this->server(self::DOMAIN);
        self::assertSame(Fixtures::profile()['identity']['description'], $server->profile['identity']['description']);
        self::assertSame('https://social.example.org/logo.png', $server->profile['identity']['logo_url']);
        self::assertSame('https://social.example.org/logo.png', $server->mediaPending['logo'] ?? null, 'the approved logo is fetched again');
        self::assertNull($server->profile['identity']['banner_url'] ?? null, 'the banner added since approval is gone');
        self::assertArrayNotHasKey('banner', $server->mediaPending ?? []);
        self::assertNull($server->bannerHash);
        self::assertSame(['reason' => 'Misleading description.'], $this->lastAudit('profile.live_changes_reverted', self::DOMAIN)->details);
        self::assertStringNotContainsString('Totally misleading text.', $crawler->filter('main')->text());
    }

    public function testListedServerIsReReviewed(): void
    {
        $this->listServer($this->enrolServer(self::DOMAIN));
        $this->clock->sleep(86400);
        $this->consoleLogin('bob@nextcloud.test');

        // A failed item records the review but does not move the next review date.
        $nextBefore = $this->server(self::DOMAIN)->nextReviewAt;
        $checked = $this->allChecked(Covenant::REREVIEW_CHECKLIST);
        unset($checked['checklist[test_signup]']);
        $this->submitForm($this->page(self::PAGE), 'form[action$="/review"]', $checked + ['notes' => 'Signup broken.']);
        $this->followFlash('success', 'Review recorded.');
        self::assertEquals($nextBefore, $this->server(self::DOMAIN)->nextReviewAt);
        self::assertFalse($this->lastAudit('review.recorded')->details['passed']);

        $this->submitForm($this->page(self::PAGE), 'form[action$="/review"]', $this->allChecked(Covenant::REREVIEW_CHECKLIST) + ['notes' => 'All fine.']);
        $this->followFlash('success', 'Review recorded.');
        $server = $this->server(self::DOMAIN);
        self::assertEquals($this->clock->now(), $server->reviewedAt);
        self::assertNotNull($server->nextReviewAt);
        self::assertGreaterThan($this->clock->now()->modify('+1 month'), $server->nextReviewAt);
        $entry = $this->lastAudit('review.recorded', self::DOMAIN);
        self::assertSame(['kind' => Review::KIND_REREVIEW, 'passed' => true, 'checklist' => ['test_report' => true, 'test_signup' => true, 'complaints' => true]], $entry->details);
    }

    public function testServerPagesNeedAKnownServerAndASignedInCurator(): void
    {
        $this->enrolServer(self::DOMAIN);
        $this->client->request('POST', self::$base.self::PAGE.'/pause', ['concern' => 'x']);
        self::assertResponseRedirects();
        self::assertStringContainsString('/console/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->createCurator('alice@nextcloud.test');
        $this->consoleLogin('alice@nextcloud.test');
        $this->client->request('GET', self::$base.'/console/servers/unknown.example.org');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::$base.'/console/servers/localhost.test');
        self::assertResponseStatusCodeSame(404);
        foreach (['pause', 'waitlist', 'comment', 'review', 'conflict', 'check', 'profile/approve'] as $action) {
            $this->post('/console/servers/unknown.example.org/'.$action, ['note' => 'x', 'body' => 'x', 'concern' => 'x']);
            self::assertResponseStatusCodeSame(404, $action);
        }

        // Server list filters.
        $this->page('/console/servers?state=applied&q=SOCIAL');
        self::assertSelectorTextContains('main table', self::DOMAIN);
        $this->page('/console/servers?state=listed');
        self::assertSelectorTextNotContains('main', self::DOMAIN.' ');
        $crawler = $this->page('/console/servers?q=nomatch');
        self::assertCount(0, $crawler->filter('main table a[href="'.self::PAGE.'"]'));
    }

    /**
     * Submits the decision form of the server page.
     *
     * @param array<string, mixed> $values
     */
    private function decide(array $values, string $flash, string $type = 'success'): void
    {
        $this->submitForm($this->page(self::PAGE), 'form[action$="/decide"]', $values);
        $this->followFlash($type, $flash, self::PAGE);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $items
     *
     * @return array<string, string> form values that tick every item
     */
    private function allChecked(array $items): array
    {
        $values = [];
        foreach (array_keys($items) as $key) {
            $values["checklist[{$key}]"] = '1';
        }

        return $values;
    }

    /**
     * The server sends a changed profile (a new etag each time).
     *
     * @param callable(array<string, mixed>&): void $change
     */
    private function changeProfile(FakeAlohaServer $fake, callable $change): void
    {
        static $etag = 100;
        $profile = Fixtures::profile(self::DOMAIN);
        $change($profile);
        $profile['etag'] = 'W/"'.++$etag.'"';
        $this->clock->sleep(1);
        $this->api('PUT', '/api/v1/servers/'.self::DOMAIN.'/profile', $profile, $fake);
    }
}
