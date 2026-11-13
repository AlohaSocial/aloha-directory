<?php

declare(strict_types=1);

namespace App\Check;

use App\Entity\Server;
use App\Entity\ServerCheck;
use App\Entity\ServerCheckDaily;
use App\Enum\ServerState;
use App\Net\DnsResolver;
use App\Net\FetchException;
use App\Net\SafeHttpClient;
use App\Repository\HeartbeatRepository;
use App\Service\Blocklist;
use App\Service\DirectorySettings;
use App\Service\Notifier;
use App\Service\ServerLifecycle;
use App\Service\VersionComparator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Runs the automatic checks of concept 7.2 for one server and applies their consequences:
 * an application whose checks all pass goes to the curators; a listed server whose critical
 * checks keep failing is paused (ServerLifecycle::evaluate).
 */
final class Checker
{
    private const ABUSE_TEST_MAIL_INTERVAL = '24 hours';
    private const PAGE_MAX_BYTES = 3_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SafeHttpClient $http,
        private readonly NodeinfoReader $nodeinfo,
        private readonly DnsResolver $dns,
        private readonly Blocklist $blocklist,
        private readonly HeartbeatRepository $heartbeats,
        private readonly ServerLifecycle $lifecycle,
        private readonly Notifier $notifier,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, ServerCheck>
     */
    public function run(Server $server): array
    {
        if (!$server->state->isActive()) {
            return [];
        }

        $results = [
            Checks::HTTPS => $this->checkHttps($server),
            Checks::NODEINFO => $this->checkNodeinfo($server),
            Checks::TERMS => $this->checkTerms($server),
            Checks::ABUSE_CONTACT => $this->checkAbuseContact($server),
            Checks::INSTANCE_SIGNUP => $this->checkInstanceSignup($server),
            Checks::BLOCKLIST => $this->checkBlocklist($server),
            Checks::HEARTBEATS => $this->checkHeartbeats($server),
            Checks::PROFILE => null !== $server->profile ? [true, 'Profile received.'] : [false, 'No profile has been sent yet.'],
        ];
        if (true === ($server->profile['atproto']['enabled'] ?? false)) {
            $results[Checks::ATPROTO] = $this->checkAtproto($server);
        }

        // The checks above only read. Their results are applied to a freshly read, locked row,
        // because a heartbeat or a curator may have changed the server during the requests.
        return $this->lifecycle->underLock($server, function (Server $server) use ($results): array {
            if (!$server->state->isActive()) {
                return [];
            }
            $now = $this->clock->now();
            $repo = $this->em->getRepository(ServerCheck::class);
            $records = [];
            foreach ($repo->findBy(['server' => $server]) as $existing) {
                $records[$existing->name] = $existing;
            }
            $day = $now->format('Y-m-d');
            $daily = [];
            foreach ($this->em->getRepository(ServerCheckDaily::class)->findBy(['server' => $server, 'day' => $day]) as $row) {
                $daily[$row->name] = $row;
            }
            foreach ($results as $name => [$passed, $message]) {
                $record = $records[$name] ?? new ServerCheck($server, $name, $now);
                $record->record($passed, $message, $now);
                $this->em->persist($record);
                $records[$name] = $record;

                $history = $daily[$name] ?? new ServerCheckDaily($server, $day, $name);
                $passed ? ++$history->passes : ++$history->failures;
                $this->em->persist($history);
            }
            if (!isset($results[Checks::ATPROTO]) && isset($records[Checks::ATPROTO])) {
                $this->em->remove($records[Checks::ATPROTO]);
                unset($records[Checks::ATPROTO]);
            }
            $server->atprotoVerified = isset($results[Checks::ATPROTO]) ? $results[Checks::ATPROTO][0] : null;
            if ($results[Checks::ABUSE_CONTACT][0]) {
                $this->sendAbuseTestMail($server, $now);
            }

            if (ServerState::Applied === $server->state) {
                $allPass = true;
                foreach (Checks::BEFORE_REVIEW as $name) {
                    $allPass = $allPass && ($records[$name]->passed ?? false);
                }
                if ($allPass) {
                    $this->lifecycle->markInReview($server);
                    $this->notifier->curators(\sprintf('New application: %s', $server->domain), \sprintf("%s passed all automatic checks and waits for review.\n\n%s/console/servers/%s", $server->domain, $this->settings->publicUrl, $server->domain));
                }
            }

            $this->em->flush();
            $this->lifecycle->evaluate($server);

            return $records;
        });
    }

    /**
     * One test message per new abuse address, and at most one per server per day, so a server
     * cannot use the directory to send mail to arbitrary addresses by changing it often.
     */
    private function sendAbuseTestMail(Server $server, \DateTimeImmutable $now): void
    {
        $email = $server->profile['moderation']['abuse_email'] ?? null;
        if (!\is_string($email) || $server->abuseTestMailSentTo === $email) {
            return;
        }
        if (null !== $server->abuseTestMailSentAt && $server->abuseTestMailSentAt > $now->modify('-'.self::ABUSE_TEST_MAIL_INTERVAL)) {
            return;
        }
        $this->notifier->send($email, 'Test message for the abuse contact of '.$server->domain,
            "This is an automatic test from the Aloha Directory.\n\nThe server {$server->domain} lists this address as its abuse contact. No reply is needed. If you did not expect this message, please tell us by answering it.");
        $server->abuseTestMailSentTo = $email;
        $server->abuseTestMailSentAt = $now;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkHttps(Server $server): array
    {
        try {
            $result = $this->http->get('https://'.$server->domain.'/status.php', 65_536, ['Accept' => 'application/json']);
        } catch (FetchException $e) {
            return [false, $e->getMessage()];
        }
        if (200 !== $result->status) {
            return [false, \sprintf('/status.php answered HTTP %d.', $result->status)];
        }
        try {
            $status = $result->json(4);
        } catch (FetchException $e) {
            return [false, $e->getMessage()];
        }
        if (true !== ($status['installed'] ?? null) || true === ($status['maintenance'] ?? false)) {
            return [false, 'Nextcloud reports it is not installed or in maintenance mode.'];
        }
        $hsts = $result->header('strict-transport-security');

        return [true, null !== $hsts ? 'Valid certificate; HSTS enabled.' : 'Valid certificate; HSTS is recommended but not enabled.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkNodeinfo(Server $server): array
    {
        try {
            $info = $this->nodeinfo->read($server->domain);
        } catch (FetchException $e) {
            return [false, $e->getMessage()];
        }
        if (!\in_array($info->softwareName, $this->settings->softwareNames, true)) {
            return [false, \sprintf('Nodeinfo names "%s", not Aloha.', $info->softwareName)];
        }
        if (!VersionComparator::atLeast($info->version, $this->settings->minServerVersion)) {
            // Not a failure: the minimum-version rule has its own 30-day grace period (concept 13.6).
            return [true, \sprintf('Aloha %s; below the minimum %s, the server will be paused after the grace period.', $info->version, $this->settings->minServerVersion)];
        }
        if ($info->version !== $server->softwareVersion && null !== $server->softwareVersion) {
            return [true, \sprintf('Aloha %s (the heartbeat reports %s).', $info->version, $server->softwareVersion)];
        }

        return [true, \sprintf('Aloha %s.', $info->version)];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkTerms(Server $server): array
    {
        if (null === $server->profile) {
            return [false, 'No profile has been sent yet.'];
        }
        $terms = $server->pendingProfile['terms'] ?? $server->profile['terms'] ?? [];
        if ([] === ($terms['rules'] ?? [])) {
            return [false, 'The rules list is empty.'];
        }
        $urls = [
            'terms of service' => $terms['terms_url'] ?? null,
            'privacy policy' => $terms['privacy_url'] ?? null,
            'moderation policy' => $server->profile['moderation']['policy_url'] ?? null,
        ];
        foreach ($urls as $label => $url) {
            if (!\is_string($url)) {
                return [false, \sprintf('The %s URL is missing.', $label)];
            }
            try {
                $result = $this->http->get($url, self::PAGE_MAX_BYTES, ['Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.5'], false);
            } catch (FetchException $e) {
                return [false, \sprintf('The %s does not load: %s', $label, $e->getMessage())];
            }
            if (200 !== $result->status) {
                return [false, \sprintf('The %s answered HTTP %d.', $label, $result->status)];
            }
        }

        return [true, \sprintf('Terms, privacy policy and moderation policy load; %d rules.', \count($terms['rules']))];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkAbuseContact(Server $server): array
    {
        $email = $server->profile['moderation']['abuse_email'] ?? null;
        if (!\is_string($email)) {
            return [false, 'No abuse contact is set.'];
        }
        $domain = substr((string) strrchr($email, '@'), 1);
        if (!$this->dns->canReceiveMail($domain)) {
            return [false, \sprintf('The domain of %s cannot receive mail (no MX or address record).', $email)];
        }
        if ($server->abuseTestMailSentTo !== $email) {
            return [true, \sprintf('%s can receive mail; a test message is sent to it.', $email)];
        }

        return [true, \sprintf('%s can receive mail; test message sent %s.', $email, $server->abuseTestMailSentAt?->format('Y-m-d') ?? 'earlier')];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkInstanceSignup(Server $server): array
    {
        try {
            $result = $this->http->get('https://'.$server->domain.'/api/v2/instance', 1_000_000, ['Accept' => 'application/json']);
            if (200 !== $result->status) {
                return [false, \sprintf('/api/v2/instance answered HTTP %d.', $result->status)];
            }
            $instance = $result->json(32);
        } catch (FetchException $e) {
            return [false, $e->getMessage()];
        }
        $registrations = $instance['registrations'] ?? null;
        if (!\is_array($registrations) || !\is_bool($registrations['enabled'] ?? null)) {
            return [false, '/api/v2/instance has no registrations block.'];
        }
        if (null === $server->signupOpen) {
            return [false, 'No heartbeat has been received yet.'];
        }
        if ($registrations['enabled'] !== $server->signupOpen) {
            return [false, \sprintf('/api/v2/instance says registrations are %s, the heartbeat says %s.', $registrations['enabled'] ? 'open' : 'closed', $server->signupOpen ? 'open' : 'closed')];
        }
        if ($server->signupOpen && ($registrations['approval_required'] ?? null) !== ('approval' === $server->signupMode)) {
            return [false, '/api/v2/instance and the heartbeat disagree about approval.'];
        }

        return [true, $server->signupOpen ? \sprintf('Signup is open (%s), as in the heartbeat.', $server->signupMode) : 'Signup is closed, as in the heartbeat.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkBlocklist(Server $server): array
    {
        $block = $this->blocklist->match($server->domain);

        return null === $block ? [true, 'Not on any block list.'] : [false, \sprintf('Blocked by "%s" (%s): %s', $block->source, $block->domain, $block->reason)];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkHeartbeats(Server $server): array
    {
        $latest = $this->heartbeats->latestForServer($server, 3);
        if (\count($latest) < 3) {
            return [false, \sprintf('%d of 3 heartbeats received.', \count($latest))];
        }
        $now = $this->clock->now();
        $maxGap = 2 * 3600;
        if ($now->getTimestamp() - $latest[0]->receivedAt->getTimestamp() > $maxGap) {
            return [false, 'The last heartbeat is more than two hours old.'];
        }
        for ($i = 0; $i < 2; ++$i) {
            if ($latest[$i]->receivedAt->getTimestamp() - $latest[$i + 1]->receivedAt->getTimestamp() > $maxGap) {
                return [false, 'The last three heartbeats were not on time.'];
            }
        }

        return [true, 'The last three heartbeats arrived on time.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkAtproto(Server $server): array
    {
        try {
            $did = $this->http->get('https://'.$server->domain.'/.well-known/did.json', 65_536, ['Accept' => 'application/json'])->json(16);
        } catch (FetchException $e) {
            return [false, $e->getMessage()];
        }
        if (!\is_string($did['id'] ?? null) || !str_starts_with($did['id'], 'did:')) {
            return [false, '/.well-known/did.json has no DID.'];
        }
        $handle = $server->profile['atproto']['handle_example'] ?? null;
        if (\is_string($handle) && str_ends_with($handle, '.'.$server->domain)) {
            try {
                $result = $this->http->get('https://'.$handle.'/.well-known/atproto-did', 1024, [], true);
            } catch (FetchException $e) {
                return [false, \sprintf('The example handle %s does not resolve: %s', $handle, $e->getMessage())];
            }
            if (200 !== $result->status || !str_starts_with(trim($result->body), 'did:')) {
                return [false, \sprintf('The example handle %s does not resolve to a DID.', $handle)];
            }
        }

        return [true, 'did.json and handle resolution work.'];
    }
}
