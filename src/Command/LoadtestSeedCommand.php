<?php

declare(strict_types=1);

namespace App\Command;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Entity\Server;
use App\Enum\ServerState;
use App\Lists\MirrorPusher;
use App\Service\AuditLogger;
use App\Service\DirectorySettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates (or removes) listed fake servers for load tests on a staging copy (docs/loadtest.md).
 *
 * Their domains are lt00001.loadtest.<directory host>, which only the operator controls, so the
 * checker's requests to them go nowhere. Each key pair is derived from LOADTEST_SEED and the domain
 * (Ed25519 seed = SHA-256("aloha-loadtest:<seed>:<domain>")), so tools/loadtest can sign as every
 * fake server without a key file.
 */
#[AsCommand('app:loadtest:seed', 'Staging only: adds or removes fake listed servers for the k6 load test')]
final class LoadtestSeedCommand
{
    public const LABEL = 'loadtest';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly DirectorySettings $settings,
        private readonly MirrorPusher $mirror,
        private readonly ClockInterface $clock,
        private readonly AuditLogger $audit,
        #[Autowire('%env(bool:DIRECTORY_LOADTEST_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Number of fake servers')] int $servers = 1000,
        #[Option('Remove all fake servers instead')] bool $purge = false,
        #[Option('Confirms that this is not the production directory')] bool $notProduction = false,
    ): int {
        if (!$this->enabled) {
            $io->error('Load testing is disabled. Set \'loadtest_enabled\' => true in config.php, on a staging copy only.');

            return Command::INVALID;
        }
        if (!$notProduction) {
            $io->error('This adds fake servers to the lists. Run it only on a staging copy, with --not-production.');

            return Command::INVALID;
        }
        if ($this->mirror->enabled()) {
            $io->error('The public Git mirror is configured (mirror_repo_dir): fake servers would be published. Disable it on staging.');

            return Command::INVALID;
        }
        $suffix = self::suffix($this->settings->publicUrl);

        if ($purge) {
            $deleted = $this->db->executeStatement('DELETE FROM server WHERE domain LIKE ?', ['lt%'.$suffix]);
            $this->audit->system('loadtest.purged', null, ['servers' => $deleted]);
            $this->em->flush();
            $io->success(\sprintf('Removed %d fake servers.', $deleted));

            return Command::SUCCESS;
        }
        // From the environment, so the secret stays out of the shell history and the process list.
        $seed = (string) ($_SERVER['LOADTEST_SEED'] ?? getenv('LOADTEST_SEED') ?: '');
        if (\strlen($seed) < 16) {
            $io->error('Set LOADTEST_SEED to a secret of at least 16 characters.');

            return Command::INVALID;
        }
        if ($servers < 1 || $servers > 100_000) {
            $io->error('--servers must be between 1 and 100000.');

            return Command::INVALID;
        }

        $now = $this->clock->now();
        $languages = ['de', 'en', 'fr', 'es', 'it', 'nl', 'pt', 'pl'];
        $countries = ['DE', 'FR', 'NL', 'ES', 'IT', 'AT', 'CH', 'SE'];
        $existing = array_flip(array_map('strval', $this->db->fetchFirstColumn('SELECT domain FROM server WHERE domain LIKE ?', ['lt%'.$suffix])));
        $created = 0;
        for ($i = 1; $i <= $servers; ++$i) {
            $domain = self::domain($i, $suffix);
            if (isset($existing[$domain])) {
                continue;
            }
            [, $public] = self::keyPair($seed, $domain);
            $server = new Server($domain, $now);
            $server->state = ServerState::Listed;
            $server->publicKey = Base64Url::encode($public);
            $server->keyId = Ed25519::fingerprint($public);
            $server->verifiedAt = $now;
            $server->appliedAt = $now;
            $server->listedAt = $now->modify('-'.($i % 365).' days');
            $server->reviewedAt = $now;
            $server->featured = 0 === $i % 3;
            $server->lastHeartbeatAt = $now;
            $server->signupOpen = true;
            $server->signupMode = 'approval';
            $server->availableAccounts = 50 + $i % 500;
            $server->approvalBacklog = $i % 7;
            $server->softwareVersion = $this->settings->minServerVersion;
            $server->federationOk = true;
            $server->usersTotal = 1000 + $i;
            $language = $languages[$i % \count($languages)];
            $country = $countries[$i % \count($countries)];
            $profile = [
                'identity' => ['name' => 'Load test '.$i, 'description' => 'A fake server for load testing.', 'long_description' => null, 'logo_url' => null, 'banner_url' => null],
                'audience' => ['languages' => [$language, 'en'], 'topic' => 'general', 'audience' => null],
                'terms' => ['terms_url' => 'https://'.$domain.'/terms', 'privacy_url' => 'https://'.$domain.'/privacy', 'rules' => ['Be kind.'], 'min_age' => 16],
                'jurisdiction' => ['hosting_country' => $country, 'operator_seat' => $country, 'gdpr' => true, 'dpa_url' => null],
                'operator' => ['name' => 'Load test', 'type' => 'association', 'website' => null, 'funding' => null, 'commitment_until' => null, 'shutdown_notice_months' => null],
                'moderation' => ['moderators' => 3, 'abuse_email' => 'abuse@'.$domain, 'abuse_actor' => null, 'response_hours' => 24, 'blocklist_policy' => null, 'policy_url' => null],
                'account' => ['signup_mode' => 'approval', 'email_required' => true, 'storage_mb' => 1024, 'media_mb' => 40, 'features' => []],
                'search' => null,
                'atproto' => null,
                'etag' => 'W/"loadtest"',
            ];
            $server->profile = $profile;
            $server->approvedProfile = $profile;
            $server->profileEtag = 'W/"loadtest"';
            $server->updatedAt = $now;
            $this->em->persist($server);
            ++$created;
            if (0 === $created % 500) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->audit->system('loadtest.seeded', null, ['servers' => $created, 'suffix' => $suffix]);
        $this->em->flush();
        $io->success(\sprintf('Created %d fake servers (%s … %s).', $created, self::domain(1, $suffix), self::domain($servers, $suffix)));
        $io->writeln(\sprintf('Run k6 with: -e SEED="$LOADTEST_SEED" -e SERVERS=%d -e SUFFIX=%s (see docs/loadtest.md)', $servers, $suffix));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string} secret key and public key
     */
    public static function keyPair(string $seed, string $domain): array
    {
        $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'aloha-loadtest:'.$seed.':'.$domain, true));

        return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
    }

    public static function suffix(string $publicUrl): string
    {
        $host = parse_url($publicUrl, \PHP_URL_HOST);

        return '.'.self::LABEL.'.'.(\is_string($host) ? strtolower($host) : 'localhost');
    }

    public static function domain(int $n, string $suffix): string
    {
        return \sprintf('lt%05d', $n).$suffix;
    }
}
