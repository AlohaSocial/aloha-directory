<?php

declare(strict_types=1);

namespace App\Lists;

use App\Entity\ListBuild;
use App\Service\SystemStateStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Pushes every published list to the public Git mirror (concept 8.4, 12.5). MIRROR_REPO_DIR is a
 * clone of alohasocial/directory-lists with push access configured (deploy key). Optional.
 */
final class MirrorPusher
{
    public function __construct(
        #[Autowire('%env(default::MIRROR_REPO_DIR)%')]
        private readonly ?string $repoDir,
        private readonly SystemStateStore $state,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return null !== $this->repoDir && '' !== $this->repoDir;
    }

    /**
     * @param list<ListBuild> $builds
     */
    public function push(array $builds): bool
    {
        if (!$this->enabled() || [] === $builds) {
            return true;
        }
        $dir = rtrim((string) $this->repoDir, '/');
        try {
            $this->git($dir, ['pull', '--ff-only', '--quiet']);
            $messages = [];
            foreach ($builds as $build) {
                AtomicFileWriter::write($dir.'/lists/v1/'.$build->list.'.json', $build->envelope);
                $this->git($dir, ['add', 'lists/v1/'.$build->list.'.json']);
                $messages[] = \sprintf('%s serial %s (%s, %d entries)', $build->list, $build->serial, $build->reason, $build->entryCount);
            }
            $status = $this->git($dir, ['status', '--porcelain']);
            if ('' !== trim($status)) {
                $this->git($dir, ['-c', 'user.name=Aloha Directory', '-c', 'user.email=directory@aloha.social', 'commit', '--quiet', '-m', implode("\n", $messages)]);
                $this->git($dir, ['push', '--quiet']);
            }
            $this->state->set('mirror_last_ok', (new \DateTimeImmutable())->format(\DATE_ATOM));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Pushing the lists to the mirror failed.', ['exception' => $e]);

            return false;
        }
    }

    /**
     * Publishes one more file (such as a signed audit chain head) in the mirror.
     */
    public function pushFile(string $relativePath, string $content, string $message): bool
    {
        if (!$this->enabled()) {
            return true;
        }
        if (1 !== preg_match('#\A[a-z0-9][a-z0-9/._-]*\z#', $relativePath) || str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Invalid mirror path.');
        }
        $dir = rtrim((string) $this->repoDir, '/');
        try {
            $this->git($dir, ['pull', '--ff-only', '--quiet']);
            AtomicFileWriter::write($dir.'/'.$relativePath, $content);
            $this->git($dir, ['add', $relativePath]);
            if ('' !== trim($this->git($dir, ['status', '--porcelain']))) {
                $this->git($dir, ['-c', 'user.name=Aloha Directory', '-c', 'user.email=directory@aloha.social', 'commit', '--quiet', '-m', $message]);
                $this->git($dir, ['push', '--quiet']);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Pushing a file to the mirror failed.', ['path' => $relativePath, 'exception' => $e]);

            return false;
        }
    }

    /**
     * @param list<string> $args
     */
    private function git(string $dir, array $args): string
    {
        $process = new Process(['git', ...$args], $dir, ['GIT_TERMINAL_PROMPT' => '0'], null, 60);
        $process->mustRun();

        return $process->getOutput();
    }
}
