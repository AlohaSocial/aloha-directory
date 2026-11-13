<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Comment;
use App\Entity\Server;
use App\Entity\ServerCheck;
use App\Enum\CommentVisibility;
use App\Lists\ServerListEntryFactory;
use App\Repository\ComplaintRepository;
use App\Service\DirectorySettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a server admin sees in the Aloha admin settings (concept 4.2, 7.5, 13.4): the listing
 * state, the published buckets, automatic check results, curator questions and complaints
 * (without the reporter's identity). Internal curator notes are never included.
 */
final class StatusPresenter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplaintRepository $complaints,
        private readonly ServerListEntryFactory $entries,
        private readonly DirectorySettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Server $server): array
    {
        $checks = [];
        foreach ($this->em->getRepository(ServerCheck::class)->findBy(['server' => $server], ['name' => 'ASC']) as $check) {
            $checks[] = ['name' => $check->name, 'passed' => $check->passed, 'message' => $check->message, 'checked_at' => self::date($check->checkedAt)];
        }

        $comments = [];
        $rows = $this->em->getRepository(Comment::class)->findBy(['server' => $server, 'visibility' => CommentVisibility::Admin, 'complaint' => null], ['createdAt' => 'DESC'], 100);
        foreach ($rows as $comment) {
            $comments[] = ['id' => $comment->id, 'from' => $comment->fromServer ? 'server' : 'curators', 'body' => $comment->body, 'created_at' => self::date($comment->createdAt)];
        }

        $complaints = [];
        foreach ($this->complaints->findVisibleToAdmin($server) as $complaint) {
            $responses = [];
            foreach ($this->em->getRepository(Comment::class)->findBy(['complaint' => $complaint, 'visibility' => CommentVisibility::Admin], ['createdAt' => 'ASC']) as $reply) {
                $responses[] = ['from' => $reply->fromServer ? 'server' : 'curators', 'body' => $reply->body, 'created_at' => self::date($reply->createdAt)];
            }
            $complaints[] = [
                'id' => $complaint->id,
                'category' => $complaint->category,
                'body' => $complaint->body,
                'status' => $complaint->status->value,
                'created_at' => self::date($complaint->createdAt),
                'responses' => $responses,
            ];
        }

        $published = null;
        if ('listed' === $server->state->value && null !== $server->profile) {
            $entry = $this->entries->entry($server);
            $published = ['state' => $entry['state'], 'signup' => $entry['signup'], 'weight' => $entry['weight']];
        }

        $warnings = [];
        if (null !== $server->belowMinVersionSince) {
            $warnings[] = \sprintf('Aloha %s is below the minimum version %s. Update within %d days of %s or the listing is paused.',
                (string) $server->softwareVersion, $this->settings->minServerVersion, $this->settings->int('min_version_grace_days'), $server->belowMinVersionSince->format('Y-m-d'));
        }

        return [
            'domain' => $server->domain,
            'state' => $server->state->value,
            'featured' => $server->featured,
            'search_provider' => $server->searchProvider,
            'pause' => null !== $server->pauseKind ? ['kind' => $server->pauseKind->value, 'reason' => $server->pauseReason, 'since' => self::date($server->pausedAt)] : null,
            'decision_reason' => $server->decisionReason,
            'published' => $published,
            'last_heartbeat_at' => self::date($server->lastHeartbeatAt),
            'profile' => [
                'requested' => $server->profileRequested,
                'pending_review' => null !== $server->pendingProfile,
                'updated_at' => self::date($server->profileUpdatedAt),
                'etag' => $server->profileEtag,
            ],
            'media_error' => $server->mediaError,
            'reviewed_at' => self::date($server->reviewedAt),
            'next_review_at' => self::date($server->nextReviewAt),
            'checks' => $checks,
            'comments' => $comments,
            'complaints' => $complaints,
            'min_server_version' => $this->settings->minServerVersion,
            'warnings' => $warnings,
        ];
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
