<?php

declare(strict_types=1);

namespace App\Api;

use App\Service\ApiProblem;
use App\Service\DirectorySettings;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Limits for the anonymous and expensive endpoints (per IP address) and for messages from servers
 * to the curators (per authenticated server), with values from config.php. Limiter keys are an HMAC with the app secret, so the stored state cannot be turned
 * back into IP addresses; it expires with the window (concept 8.4: no IP kept beyond abuse
 * prevention).
 */
final class RateLimits
{
    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    public function __construct(
        private readonly DirectorySettings $settings,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function consume(string $name, string $key): void
    {
        $limit = $this->factory($name)->create(hash_hmac('sha256', $name."\0".self::normalizeKey($key), $this->secret))->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new ApiProblem(429, 'rate_limited', 'Too many requests; try again later.', [], ['Retry-After' => (string) $retry]);
        }
    }

    /**
     * Like consume(), but answers instead of throwing (for the console).
     */
    public function tryConsume(string $name, string $key): bool
    {
        return $this->factory($name)->create(hash_hmac('sha256', $name."\0".self::normalizeKey($key), $this->secret))->consume()->isAccepted();
    }

    /**
     * An IPv6 address counts as its /64: one host usually controls the whole prefix.
     */
    private static function normalizeKey(string $key): string
    {
        if (str_contains($key, ':') && false !== ($packed = @inet_pton($key)) && 16 === \strlen($packed)) {
            return (string) inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
        }

        return $key;
    }

    private function factory(string $name): RateLimiterFactory
    {
        return $this->factories[$name] ??= new RateLimiterFactory([
            'id' => 'aloha_'.$name,
            'policy' => 'sliding_window',
            'limit' => match ($name) {
                'enrol' => $this->settings->int('enrol_per_ip_hour'),
                'verify' => 30,
                // A real enrolment needs a few attempts; this bounds outbound fetches per key.
                'verify_key' => 12,
                'report' => $this->settings->int('reports_per_ip_hour'),
                'telemetry' => 60,
                // Password checks in the console's account forms, per curator.
                'account_confirm' => 20,
                // Comments and complaint responses, per authenticated server (only it can use its quota).
                'server_message' => $this->settings->int('messages_per_server_day'),
                default => throw new \LogicException('Unknown limiter '.$name),
            },
            'interval' => 'server_message' === $name ? '1 day' : '1 hour',
        ], new CacheStorage($this->cache));
    }
}
