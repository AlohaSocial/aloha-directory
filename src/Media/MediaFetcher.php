<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Server;
use App\Lists\AtomicFileWriter;
use App\Net\FetchException;
use App\Net\SafeHttpClient;
use App\Repository\ServerRepository;
use App\Service\DirectorySettings;
use App\Service\ServerLifecycle;
use Psr\Log\LoggerInterface;

/**
 * Fetches logos and banners named in profiles and republishes them as content-addressed WebP
 * (concept 8.1: /media/<sha256>.webp, referenced by hash so the list signature covers them).
 *
 * Images are decoded and re-encoded, never passed through: this strips metadata and any polyglot
 * payload, and bounds the dimensions before decoding to avoid decompression bombs.
 */
final class MediaFetcher
{
    private const MAX_BYTES = 5_000_000;
    /**
     * GD's pixel buffers are not counted against memory_limit, so this cap is what bounds memory
     * (about 64 MB for a truecolor image of 4096x4096) and resampling time.
     */
    private const MAX_SOURCE_PIXELS = 16_777_216;
    private const SIZES = ['logo' => [512, 512], 'banner' => [1500, 500]];
    private const ALLOWED_TYPES = [\IMAGETYPE_PNG, \IMAGETYPE_JPEG, \IMAGETYPE_WEBP, \IMAGETYPE_GIF];

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly SafeHttpClient $http,
        private readonly DirectorySettings $settings,
        private readonly LoggerInterface $logger,
        private readonly ServerLifecycle $lifecycle,
    ) {
    }

    public function processPending(int $limit = 20): int
    {
        $done = 0;
        foreach ($this->servers->findWithPendingMedia($limit) as $server) {
            $this->processServer($server);
            ++$done;
        }

        return $done;
    }

    public function processServer(Server $server): void
    {
        $requested = $server->mediaPending ?? [];
        $hashes = [];
        $errors = [];
        foreach ($requested as $kind => $url) {
            if (!isset(self::SIZES[$kind])) {
                continue;
            }
            try {
                $hashes[$kind] = $this->fetch((string) $url, $kind);
            } catch (\Throwable $e) {
                $errors[] = \sprintf('%s: %s', $kind, $e instanceof FetchException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'the image could not be processed');
                $this->logger->notice('Media fetch failed.', ['domain' => $server->domain, 'kind' => $kind, 'exception' => $e]);
            }
        }

        // Applied to the current row: if the server sent new image URLs meanwhile, these results
        // are stale and the next run fetches the new ones (the files are content-addressed, so an
        // unused one is collected later).
        $this->lifecycle->underLock($server, static function (Server $server) use ($requested, $hashes, $errors): void {
            if (($server->mediaPending ?? []) !== $requested) {
                return;
            }
            foreach ($hashes as $kind => $hash) {
                'logo' === $kind ? $server->logoHash = $hash : $server->bannerHash = $hash;
            }
            $server->mediaPending = null;
            $server->mediaError = [] === $errors ? null : implode(' ', $errors);
        });
    }

    /**
     * @return string the sha256 of the published WebP file
     */
    private function fetch(string $url, string $kind): string
    {
        $result = $this->http->get($url, self::MAX_BYTES, ['Accept' => 'image/webp,image/png,image/jpeg,image/gif'], false, 10.0);
        if (200 !== $result->status) {
            throw new FetchException(\sprintf('The image answered HTTP %d.', $result->status));
        }

        $info = @getimagesizefromstring($result->body);
        if (false === $info || !\in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException('The file is not a PNG, JPEG, WebP or GIF image.');
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            throw new \InvalidArgumentException('The image dimensions are out of range.');
        }

        $source = @imagecreatefromstring($result->body);
        if (false === $source) {
            throw new \InvalidArgumentException('The image cannot be decoded.');
        }
        try {
            [$maxW, $maxH] = self::SIZES[$kind];
            $w = imagesx($source);
            $h = imagesy($source);
            $scale = min(1.0, $maxW / $w, $maxH / $h);
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));

            $target = imagecreatetruecolor($tw, $th);
            if (false === $target) {
                throw new \RuntimeException('Cannot allocate the image.');
            }
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

            ob_start();
            $ok = imagewebp($target, null, 85);
            $webp = (string) ob_get_clean();
            unset($target);
            if (!$ok || '' === $webp) {
                throw new \RuntimeException('Cannot encode WebP.');
            }
        } finally {
            unset($source);
        }

        $hash = hash('sha256', $webp);
        $path = $this->settings->outputDir.'/media/'.$hash.'.webp';
        if (!is_file($path)) {
            AtomicFileWriter::write($path, $webp);
        }

        return $hash;
    }

    /**
     * Deletes media files no server references any more, after a grace period so that clients with
     * an older list still find them.
     */
    public function collectGarbage(int $graceDays = 90): int
    {
        $referenced = [];
        foreach ($this->servers->findAll() as $server) {
            foreach ([$server->logoHash, $server->bannerHash] as $hash) {
                if (null !== $hash) {
                    $referenced[$hash] = true;
                }
            }
        }
        $deleted = 0;
        foreach (glob($this->settings->outputDir.'/media/*.webp') ?: [] as $file) {
            $hash = basename($file, '.webp');
            if (!isset($referenced[$hash]) && filemtime($file) < time() - $graceDays * 86400) {
                @unlink($file) && ++$deleted;
            }
        }

        return $deleted;
    }
}
