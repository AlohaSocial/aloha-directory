<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Login throttling for the console. On top of Symfony's default limits (per IP, per account and
 * IP) it limits failed attempts per account alone, so someone who has a curator's password cannot
 * guess the TOTP code by spreading attempts over many addresses. A successful login only clears
 * the account-and-IP counter; the per-account counter runs out with its window.
 */
final class ConsoleLoginRateLimiter extends AbstractRequestRateLimiter
{
    private const PER_IP = [50, '15 minutes'];
    private const PER_ACCOUNT_AND_IP = [5, '15 minutes'];
    private const PER_ACCOUNT = [20, '1 hour'];

    private readonly RateLimiterFactory $ip;
    private readonly RateLimiterFactory $accountAndIp;
    private readonly RateLimiterFactory $account;

    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $cache,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
        $storage = new CacheStorage($cache);
        $factory = static fn (string $id, array $limit): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit[0], 'interval' => $limit[1]],
            $storage,
        );
        $this->ip = $factory('console_login_ip', self::PER_IP);
        $this->accountAndIp = $factory('console_login_account_ip', self::PER_ACCOUNT_AND_IP);
        $this->account = $factory('console_login_account', self::PER_ACCOUNT);
    }

    public function reset(Request $request): void
    {
        $this->accountAndIp->create($this->hash(self::username($request).'-'.$request->getClientIp()))->reset();
    }

    /**
     * @return list<LimiterInterface>
     */
    protected function getLimiters(Request $request): array
    {
        $username = self::username($request);

        return [
            $this->ip->create($this->hash((string) $request->getClientIp())),
            $this->accountAndIp->create($this->hash($username.'-'.$request->getClientIp())),
            $this->account->create($this->hash($username)),
        ];
    }

    private static function username(Request $request): string
    {
        $username = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');

        return 1 === preg_match('//u', $username) ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    private function hash(string $data): string
    {
        return hash_hmac('sha256', 'console-login:'.$data, $this->secret);
    }
}
