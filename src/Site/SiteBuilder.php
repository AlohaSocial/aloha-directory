<?php

declare(strict_types=1);

namespace App\Site;

use App\Crypto\Base64Url;
use App\Curation\Covenant;
use App\Entity\AuditHead;
use App\Entity\Complaint;
use App\Entity\TransparencyEntry;
use App\Entity\TransparencyReport;
use App\Enum\ListName;
use App\Lists\AtomicFileWriter;
use App\Lists\KeySetService;
use App\Repository\ListBuildRepository;
use App\Service\DirectorySettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Renders the public website as static HTML (concept 13.5, D20). The server directory is rendered
 * from the payload of the latest signed servers.json, so the website shows exactly what the apps see.
 */
final class SiteBuilder
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ListBuildRepository $builds,
        private readonly KeySetService $keys,
        private readonly EntityManagerInterface $em,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
        private readonly ServerHistory $history,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<string> written paths, relative to the output directory
     */
    public function build(): array
    {
        $servers = $this->latestPayload(ListName::Servers);
        $entries = $servers['entries'] ?? [];
        $common = [
            'generated_at' => $this->clock->now(),
            'list_serial' => $servers['serial'] ?? null,
            'list_issued_at' => $servers['issued_at'] ?? null,
            'media_base' => '/media/',
        ];

        $languages = [];
        $countries = [];
        $topics = [];
        foreach ($entries as $entry) {
            foreach ($entry['languages'] ?? [] as $language) {
                $languages[$language] = true;
            }
            if (isset($entry['region']['hosting'])) {
                $countries[$entry['region']['hosting']] = true;
            }
            $topics[$entry['topic'] ?? 'general'] = true;
        }
        ksort($languages);
        ksort($countries);
        ksort($topics);

        $pages = [
            'index.html' => ['site/index.html.twig', ['servers' => $entries, 'languages' => array_keys($languages), 'countries' => array_keys($countries), 'topics' => array_keys($topics)]],
            'covenant/index.html' => ['site/covenant.html.twig', ['rules' => Covenant::RULES]],
            'criteria/index.html' => ['site/criteria.html.twig', ['initial' => Covenant::INITIAL_CHECKLIST, 'rereview' => Covenant::REREVIEW_CHECKLIST]],
            'privacy/index.html' => ['site/privacy.html.twig', []],
            'transparency/index.html' => ['site/transparency.html.twig', [
                'delistings' => $this->em->getRepository(TransparencyEntry::class)->findBy([], ['decidedAt' => 'DESC'], 500),
                'reports' => $this->em->getRepository(TransparencyReport::class)->findBy([], ['quarter' => 'DESC']),
                'history' => [
                    'servers' => $this->builds->history(ListName::Servers->value, 50),
                    'search' => $this->builds->history(ListName::Search->value, 50),
                    'keys' => $this->builds->history(ListName::Keys->value, 20),
                ],
                'audit_heads' => $this->em->getRepository(AuditHead::class)->findBy([], ['day' => 'DESC'], 60),
                'reasons' => Covenant::delistingReasons(),
                'complaint_categories' => Complaint::CATEGORIES,
            ]],
            'keys/index.html' => ['site/keys.html.twig', ['keyset' => $this->keys->installed()['payload'] ?? null, 'root_keys' => array_map(Base64Url::encode(...), $this->keys->rootKeys())]],
        ];
        $history = $this->history->forEntries($entries);
        foreach ($entries as $entry) {
            $pages['servers/'.$entry['domain'].'/index.html'] = ['site/server.html.twig', ['server' => $entry, 'history' => $history[$entry['domain']] ?? null]];
        }

        $written = [];
        foreach ($pages as $path => [$template, $context]) {
            AtomicFileWriter::write($this->settings->outputDir.'/'.$path, $this->twig->render($template, $common + $context));
            $written[] = $path;
        }
        $this->removeStaleServerPages(array_column($entries, 'domain'));
        $this->copyAssets();

        return $written;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestPayload(ListName $list): array
    {
        $build = $this->builds->latest($list->value);
        if (null === $build) {
            return [];
        }
        $payload = json_decode($build->payload, true);

        return \is_array($payload) ? $payload : [];
    }

    /**
     * @param list<string> $domains
     */
    private function removeStaleServerPages(array $domains): void
    {
        $keep = array_flip($domains);
        foreach (glob($this->settings->outputDir.'/servers/*/index.html') ?: [] as $file) {
            $domain = basename(\dirname($file));
            if (!isset($keep[$domain])) {
                @unlink($file);
                @rmdir(\dirname($file));
            }
        }
    }

    private function copyAssets(): void
    {
        $source = $this->projectDir.'/public/assets';
        $target = $this->settings->outputDir.'/assets';
        if (realpath($source) === realpath($target) || !is_dir($source)) {
            return;
        }
        foreach (glob($source.'/*') ?: [] as $file) {
            if (is_file($file)) {
                AtomicFileWriter::write($target.'/'.basename($file), (string) file_get_contents($file));
            }
        }
    }
}
