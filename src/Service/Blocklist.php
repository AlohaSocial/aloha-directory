<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BlockedDomain;
use App\Net\DomainName;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The directory's own block list plus imported shared lists (e.g. Oliphant tier-0, concept 7.2).
 */
final class Blocklist
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Returns the matching block (on the domain itself or any parent domain), or null.
     */
    public function match(string $domain): ?BlockedDomain
    {
        $candidates = [];
        $labels = explode('.', $domain);
        for ($i = 0; $i < \count($labels) - 1; ++$i) {
            $candidates[] = implode('.', \array_slice($labels, $i));
        }

        return $this->em->getRepository(BlockedDomain::class)->findOneBy(['domain' => $candidates]);
    }

    public function add(string $domain, string $source, string $reason): BlockedDomain
    {
        $domain = DomainName::normalize($domain);
        $existing = $this->em->getRepository(BlockedDomain::class)->findOneBy(['domain' => $domain, 'source' => $source]);
        if (null !== $existing) {
            return $existing;
        }
        $block = new BlockedDomain($domain, $source, mb_substr($reason, 0, 500), $this->clock->now());
        $this->em->persist($block);

        return $block;
    }

    /**
     * Replaces all entries of an imported source with the given domains. Mastodon-style CSV exports
     * (first column "#domain" or "domain") and plain one-domain-per-line files are accepted.
     *
     * @return array{added: int, removed: int, skipped: int}
     */
    public function importSource(string $source, string $contents): array
    {
        if (BlockedDomain::SOURCE_DIRECTORY === $source) {
            throw new \InvalidArgumentException('The directory source is managed in the console, not imported.');
        }
        $wanted = [];
        $skipped = 0;
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#domain') || str_starts_with($line, 'domain,') || str_starts_with($line, '//')) {
                continue;
            }
            $domain = trim(explode(',', $line)[0], " \t\"'#");
            if (str_contains($domain, '*')) { // obfuscated entries cannot be matched
                ++$skipped;
                continue;
            }
            try {
                $wanted[DomainName::normalize($domain)] = true;
            } catch (\InvalidArgumentException) {
                ++$skipped;
            }
        }

        $repo = $this->em->getRepository(BlockedDomain::class);
        $removed = 0;
        foreach ($repo->findBy(['source' => $source]) as $existing) {
            if (isset($wanted[$existing->domain])) {
                unset($wanted[$existing->domain]);
            } else {
                $this->em->remove($existing);
                ++$removed;
            }
        }
        $now = $this->clock->now();
        foreach (array_keys($wanted) as $domain) {
            $this->em->persist(new BlockedDomain((string) $domain, $source, 'Imported from '.$source, $now));
        }

        return ['added' => \count($wanted), 'removed' => $removed, 'skipped' => $skipped];
    }
}
