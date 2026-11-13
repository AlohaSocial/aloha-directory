<?php

declare(strict_types=1);

namespace App\Net;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Every outbound request of the directory goes through here (checker, domain verification, media).
 *
 *  - HTTPS only, default port only, certificate verification always on;
 *  - private, loopback, link-local and reserved addresses are refused, also after redirects
 *    (the injected client is wrapped in NoPrivateNetworkHttpClient in non-test environments);
 *  - redirects are followed manually, re-validated, and optionally restricted to the same host;
 *  - response bodies are capped while streaming, and every request has a hard time budget.
 */
final class SafeHttpClient
{
    private const MAX_REDIRECTS = 3;
    private const MIN_REQUEST_SECONDS = 0.25;

    public function __construct(
        #[Autowire(service: 'app.outbound_http_client')]
        private readonly HttpClientInterface $client,
        #[Autowire('%app.user_agent%')]
        private readonly string $userAgent,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param float|null            $deadline microtime(true) by which the whole call, redirects included, must be done
     */
    public function get(string $url, int $maxBytes, array $headers = [], bool $sameHostRedirectsOnly = true, float $timeout = 5.0, ?float $deadline = null): FetchResult
    {
        $originalHost = $this->assertSafeUrl($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $idle = $timeout;
            $total = $timeout * 2;
            if (null !== $deadline) {
                $left = $deadline - microtime(true);
                if ($left < self::MIN_REQUEST_SECONDS) {
                    throw new FetchException('The time budget for this check is used up.');
                }
                $idle = min($idle, $left);
                $total = min($total, $left);
            }
            $result = $this->fetchOnce($url, $maxBytes, $headers, $idle, $total);
            if ($result->status < 300 || $result->status >= 400 || null === $result->header('location')) {
                return $result;
            }
            $next = self::resolveLocation($url, (string) $result->header('location'));
            $host = $this->assertSafeUrl($next);
            if ($sameHostRedirectsOnly && $host !== $originalHost) {
                throw new FetchException(\sprintf('%s redirects to another host.', $url));
            }
            $url = $next;
        }

        throw new FetchException('Too many redirects.');
    }

    /**
     * @return string the lowercase host
     */
    public function assertSafeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || !isset($parts['host'])) {
            throw new FetchException('Only https:// URLs are fetched.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchException('URLs with credentials are not fetched.');
        }
        if (isset($parts['port']) && 443 !== $parts['port']) {
            throw new FetchException('Only the default HTTPS port is allowed.');
        }
        $host = strtolower($parts['host']);
        try {
            DomainName::normalize($host);
        } catch (\InvalidArgumentException) {
            throw new FetchException('The URL host is not a public domain name.');
        }

        return $host;
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetchOnce(string $url, int $maxBytes, array $headers, float $timeout, float $maxDuration): FetchResult
    {
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['User-Agent' => $this->userAgent, 'Accept-Encoding' => 'identity'] + $headers,
                'max_redirects' => 0,
                'timeout' => $timeout,
                'max_duration' => $maxDuration,
                'verify_peer' => true,
                'verify_host' => true,
            ]);

            // Reading the status first means 3xx/4xx/5xx are returned, not thrown.
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            if ($status >= 300 && $status < 400) {
                $response->cancel();

                return new FetchResult($url, $status, $headers, '');
            }
            $length = (int) ($headers['content-length'][0] ?? 0);
            if ($length > $maxBytes) {
                $response->cancel();
                throw new FetchException(\sprintf('%s announces more than %d bytes.', $url, $maxBytes));
            }

            $body = '';
            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > $maxBytes) {
                    $response->cancel();
                    throw new FetchException(\sprintf('%s returned more than %d bytes.', $url, $maxBytes));
                }
            }

            return new FetchResult($url, $status, $headers, $body);
        } catch (ExceptionInterface $e) {
            throw new FetchException(\sprintf('%s could not be fetched: %s', $url, $e->getMessage()), 0, $e);
        }
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (1 === preg_match('#\A[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = 'https://'.($parts['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $dir = preg_replace('#/[^/]*\z#', '/', $parts['path'] ?? '/');

        return $origin.$dir.$location;
    }
}
