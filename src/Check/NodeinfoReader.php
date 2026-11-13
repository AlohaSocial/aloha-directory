<?php

declare(strict_types=1);

namespace App\Check;

use App\Net\FetchException;
use App\Net\SafeHttpClient;

/**
 * Reads /.well-known/nodeinfo and the linked nodeinfo 2.x document of a server. The document must
 * live on the server's own host, so a server cannot borrow another server's identity.
 */
final class NodeinfoReader
{
    private const SCHEMA_RELS = [
        'http://nodeinfo.diaspora.software/ns/schema/2.2',
        'http://nodeinfo.diaspora.software/ns/schema/2.1',
        'http://nodeinfo.diaspora.software/ns/schema/2.0',
    ];

    public function __construct(private readonly SafeHttpClient $http)
    {
    }

    /**
     * @param float|null $deadline microtime(true) by which both requests must be done
     *
     * @throws FetchException
     */
    public function read(string $domain, ?float $deadline = null): NodeinfoResult
    {
        $index = $this->http->get('https://'.$domain.'/.well-known/nodeinfo', 65_536, ['Accept' => 'application/json'], deadline: $deadline);
        if (200 !== $index->status) {
            throw new FetchException(\sprintf('/.well-known/nodeinfo answered HTTP %d.', $index->status));
        }
        $links = $index->json()['links'] ?? null;
        if (!\is_array($links)) {
            throw new FetchException('/.well-known/nodeinfo has no links.');
        }

        $href = null;
        foreach (self::SCHEMA_RELS as $rel) {
            foreach ($links as $link) {
                if (\is_array($link) && ($link['rel'] ?? null) === $rel && \is_string($link['href'] ?? null)) {
                    $href = $link['href'];
                    break 2;
                }
            }
        }
        if (null === $href) {
            throw new FetchException('No nodeinfo 2.x document is linked.');
        }
        if (strtolower((string) parse_url($href, \PHP_URL_HOST)) !== $domain) {
            throw new FetchException('The nodeinfo document must be served from the server\'s own domain.');
        }

        $document = $this->http->get($href, 262_144, ['Accept' => 'application/json'], deadline: $deadline);
        if (200 !== $document->status) {
            throw new FetchException(\sprintf('The nodeinfo document answered HTTP %d.', $document->status));
        }
        $data = $document->json();
        $software = $data['software'] ?? null;
        if (!\is_array($software) || !\is_string($software['name'] ?? null) || !\is_string($software['version'] ?? null)) {
            throw new FetchException('The nodeinfo document has no software name and version.');
        }

        return new NodeinfoResult(
            strtolower($software['name']),
            mb_substr($software['version'], 0, 64),
            \is_bool($data['openRegistrations'] ?? null) ? $data['openRegistrations'] : null,
        );
    }
}
