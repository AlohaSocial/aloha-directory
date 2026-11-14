<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ListBuild;
use App\Entity\SystemState;
use App\Lists\MirrorPusher;
use App\Service\SystemStateStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Pushes to a real local Git remote (git init --bare) through a real clone.
 */
final class MirrorPusherTest extends TestCase
{
    private const ISOLATED_ENV = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_DATE' => null, 'GIT_COMMITTER_DATE' => null];

    private string $dir;
    private string $origin;
    private string $mirror;
    /** @var list<SystemState> */
    private array $persisted = [];
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $logs = [];
    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        if (null === (new ExecutableFinder())->find('git')) {
            self::markTestSkipped('git is not installed');
        }
        // Keep the developer's own Git configuration (signing, hooks, templates) out of the test.
        foreach (self::ISOLATED_ENV as $name => $value) {
            $this->savedEnv[$name] = [\array_key_exists($name, $_ENV), $_ENV[$name] ?? null, getenv($name)];
            if (null === $value) {
                continue;
            }
            $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }

        $this->dir = sys_get_temp_dir().'/aloha-mirror-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
        $this->origin = $this->dir.'/origin.git';
        $this->mirror = $this->dir.'/mirror';
        $this->git($this->dir, ['init', '--quiet', '--bare', '-b', 'main', $this->origin]);
        $this->git($this->dir, ['clone', '--quiet', $this->origin, 'seed']);
        file_put_contents($this->dir.'/seed/README.md', "Directory lists\n");
        $this->git($this->dir.'/seed', ['add', 'README.md']);
        $this->commit($this->dir.'/seed', 'Initial commit');
        $this->git($this->dir.'/seed', ['push', '--quiet', 'origin', 'HEAD:main']);
        $this->git($this->dir, ['clone', '--quiet', $this->origin, $this->mirror]);

        $this->persisted = [];
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => [$had, $value, $getenv]) {
            unset($_ENV[$name]);
            if ($had) {
                $_ENV[$name] = $value;
            }
            false === $getenv ? putenv($name) : putenv($name.'='.$getenv);
        }
        if (isset($this->dir)) {
            (new Filesystem())->remove($this->dir);
        }
    }

    public function testDisabledPusherDoesNothing(): void
    {
        foreach ([null, ''] as $repoDir) {
            $pusher = $this->pusher($repoDir);
            self::assertFalse($pusher->enabled());
            self::assertTrue($pusher->push([$this->build('servers', '1')]));
            self::assertTrue($pusher->pushFile('audit/head.json', '{}', 'm'));
        }
        self::assertSame([], $this->persisted);
        self::assertSame(1, $this->commitCount(), 'nothing reached the remote');
    }

    public function testEmptyBuildListIsANoop(): void
    {
        self::assertTrue($this->pusher($this->mirror)->push([]));
        self::assertSame([], $this->persisted);
        self::assertSame(1, $this->commitCount());
    }

    public function testPushesEveryListInOneCommit(): void
    {
        $servers = $this->build('servers', '1794586000', 'rebuild', 12);
        $search = $this->build('search', '1794586001', 'change', 3);

        self::assertTrue($this->pusher($this->mirror.'/')->push([$servers, $search]), (string) json_encode($this->logs));

        self::assertSame(2, $this->commitCount());
        self::assertSame($servers->envelope, $this->remoteFile('lists/v1/servers.json'));
        self::assertSame($search->envelope, $this->remoteFile('lists/v1/search.json'));
        self::assertSame(
            "servers serial 1794586000 (rebuild, 12 entries)\nsearch serial 1794586001 (change, 3 entries)",
            trim($this->git($this->origin, ['log', '-1', '--format=%B', 'main'])),
        );
        self::assertSame('Aloha Directory <directory@aloha.social>', trim($this->git($this->origin, ['log', '-1', '--format=%an <%ae>', 'main'])));

        self::assertCount(1, $this->persisted);
        self::assertSame('mirror_last_ok', $this->persisted[0]->name);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $this->persisted[0]->value));
        self::assertSame([], $this->logs);
    }

    public function testUnchangedListCreatesNoCommit(): void
    {
        $build = $this->build('servers', '5');
        self::assertTrue($this->pusher($this->mirror)->push([$build]));
        self::assertTrue($this->pusher($this->mirror)->push([$build]));

        self::assertSame(2, $this->commitCount(), 'the second push found nothing to commit');
        self::assertCount(2, $this->persisted, 'but it still counts as a successful mirror run');
    }

    public function testPullsChangesMadeElsewhereFirst(): void
    {
        file_put_contents($this->dir.'/seed/NOTICE', "x\n");
        $this->git($this->dir.'/seed', ['add', 'NOTICE']);
        $this->commit($this->dir.'/seed', 'Notice');
        $this->git($this->dir.'/seed', ['push', '--quiet', 'origin', 'HEAD:main']);

        self::assertTrue($this->pusher($this->mirror)->push([$this->build('servers', '6')]), (string) json_encode($this->logs));
        self::assertSame(3, $this->commitCount());
        self::assertSame("x\n", $this->remoteFile('NOTICE'));
    }

    public function testDivergedMirrorFailsAndIsLogged(): void
    {
        // Remote and mirror both have a commit the other lacks: --ff-only must refuse to merge.
        file_put_contents($this->dir.'/seed/a', "a\n");
        $this->git($this->dir.'/seed', ['add', 'a']);
        $this->commit($this->dir.'/seed', 'Remote change');
        $this->git($this->dir.'/seed', ['push', '--quiet', 'origin', 'HEAD:main']);
        file_put_contents($this->mirror.'/b', "b\n");
        $this->git($this->mirror, ['add', 'b']);
        $this->commit($this->mirror, 'Local change');

        self::assertFalse($this->pusher($this->mirror)->push([$this->build('servers', '7')]));
        self::assertSame(2, $this->commitCount(), 'nothing was pushed');
        self::assertSame([], $this->persisted, 'mirror_last_ok is not updated on failure');
        self::assertCount(1, $this->logs);
        self::assertSame('error', $this->logs[0]['level']);
        self::assertInstanceOf(\Throwable::class, $this->logs[0]['context']['exception']);
    }

    public function testMissingRepositoryFailsAndIsLogged(): void
    {
        mkdir($this->dir.'/not-a-repo');
        self::assertFalse($this->pusher($this->dir.'/not-a-repo')->push([$this->build('servers', '8')]));
        self::assertFalse($this->pusher($this->dir.'/does-not-exist')->push([$this->build('servers', '8')]));
        self::assertCount(2, $this->logs);
        self::assertSame([], $this->persisted);
    }

    public function testPushFile(): void
    {
        $pusher = $this->pusher($this->mirror);
        self::assertTrue($pusher->pushFile('audit/2026-11-13.json', "{\"seq\":5}\n", 'Audit chain head 2026-11-13'));

        self::assertSame("{\"seq\":5}\n", $this->remoteFile('audit/2026-11-13.json'));
        self::assertSame('Audit chain head 2026-11-13', trim($this->git($this->origin, ['log', '-1', '--format=%B', 'main'])));
        self::assertSame([], $this->persisted, 'pushFile does not touch mirror_last_ok');

        self::assertTrue($pusher->pushFile('audit/2026-11-13.json', "{\"seq\":5}\n", 'again'));
        self::assertSame(2, $this->commitCount(), 'unchanged file: no commit');
    }

    public function testPushFileFailureIsLogged(): void
    {
        self::assertFalse($this->pusher($this->dir.'/does-not-exist')->pushFile('audit/x.json', '{}', 'm'));
        self::assertSame('audit/x.json', $this->logs[0]['context']['path'] ?? null);
    }

    #[DataProvider('invalidPaths')]
    public function testPushFileRejectsPathsOutsideTheMirror(string $path): void
    {
        try {
            $this->pusher($this->mirror)->pushFile($path, 'pwned', 'm');
            self::fail(\sprintf('Path "%s" was accepted.', $path));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid mirror path.', $e->getMessage());
        }
        self::assertSame(1, $this->commitCount());
        self::assertFileDoesNotExist($this->dir.'/pwned');
    }

    public static function invalidPaths(): iterable
    {
        yield 'parent' => ['../pwned'];
        yield 'parent inside' => ['audit/../../pwned'];
        yield 'dot dot in name' => ['audit/x..json'];
        yield 'absolute' => ['/tmp/pwned'];
        yield 'git dir' => ['.git/hooks/post-commit'];
        yield 'hidden' => ['.github/workflows/x.yml'];
        yield 'upper case' => ['Audit/x.json'];
        yield 'space' => ['audit/x y.json'];
        yield 'newline' => ["audit/x.json\n"];
        yield 'option injection' => ['-x'];
        yield 'empty' => [''];
        yield 'backslash' => ['audit\\x.json'];
    }

    // --- helpers -------------------------------------------------------------------------------

    private function pusher(?string $repoDir): MirrorPusher
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            self::assertInstanceOf(SystemState::class, $entity);
            $this->persisted[] = $entity;
        });
        $state = new SystemStateStore($em, $this->createStub(Connection::class), new MockClock('2026-11-13 12:00:00 UTC'));

        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new MirrorPusher($repoDir, $state, $logger);
    }

    private function build(string $list, string $serial, string $reason = 'rebuild', int $entries = 1): ListBuild
    {
        $envelope = (string) json_encode(['payload' => base64_encode($list.$serial), 'signatures' => []], \JSON_PRETTY_PRINT)."\n";

        return new ListBuild($list, $serial, new \DateTimeImmutable('2026-11-13T12:00:00Z'), new \DateTimeImmutable('2026-12-13T12:00:00Z'),
            hash('sha256', $envelope), hash('sha256', $list), $entries, '{}', $envelope, ['test-list-a'], $reason);
    }

    private function commitCount(): int
    {
        return (int) trim($this->git($this->origin, ['rev-list', '--count', 'main']));
    }

    private function remoteFile(string $path): string
    {
        return $this->git($this->origin, ['show', 'main:'.$path]);
    }

    private function commit(string $dir, string $message): void
    {
        $this->git($dir, ['-c', 'user.name=Test', '-c', 'user.email=test@example.org', 'commit', '--quiet', '-m', $message]);
    }

    /**
     * @param list<string> $args
     */
    private function git(string $dir, array $args): string
    {
        $process = new Process(['git', ...$args], $dir, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->mustRun();

        return $process->getOutput();
    }
}
