<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AuditLogEntry;
use App\Entity\SystemState;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the app:* console commands in the test kernel, the way cron or an operator would.
 */
abstract class ConsoleCommandTestCase extends FunctionalTestCase
{
    /** @var list<string> files and directories created by the test, removed in tearDown() */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->scratch) as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                @chmod($path, 0o600);
                @unlink($path);
            }
        }
        $this->scratch = [];
        parent::tearDown();
    }

    protected function tester(string $name): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find($name));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: string} exit code and the output with whitespace collapsed (SymfonyStyle wraps long lines)
     */
    protected function console(string $name, array $input = [], bool $verbose = false): array
    {
        $tester = $this->tester($name);
        $exit = $tester->execute($input, $verbose ? ['verbosity' => OutputInterface::VERBOSITY_VERBOSE] : []);

        return [$exit, self::flatten($tester->getDisplay())];
    }

    protected static function flatten(string $output): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $output));
    }

    /**
     * For long words such as paths, which SymfonyStyle breaks at 120 columns: ignores all whitespace.
     */
    protected static function assertOutputContains(string $needle, string $output): void
    {
        self::assertStringContainsString((string) preg_replace('/\s+/', '', $needle), (string) preg_replace('/\s+/', '', $output), $output);
    }

    /**
     * A fresh directory for files a test writes; removed (with its files) after the test.
     */
    protected function scratchDir(): string
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir').'/var/command-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->scratch[] = $dir;

        return $dir;
    }

    protected function scratchFile(string $dir, string $name, ?string $contents = null, int $mode = 0o600): string
    {
        $path = $dir.'/'.$name;
        $this->scratch[] = $path;
        if (null !== $contents) {
            file_put_contents($path, $contents);
            chmod($path, $mode);
        }

        return $path;
    }

    protected function outputDir(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/var/test-output';
    }

    /**
     * @return list<AuditLogEntry>
     */
    protected function auditEntries(string $action): array
    {
        $this->em->clear();

        return $this->em->getRepository(AuditLogEntry::class)->findBy(['action' => $action], ['id' => 'ASC']);
    }

    protected function systemState(string $name): ?SystemState
    {
        $this->em->clear();

        return $this->em->find(SystemState::class, $name);
    }

    /**
     * Moves the mock clock to the wall clock, for commands that check signatures against real time
     * (app:keys:sign-keyset, app:lists:verify), so the test does not depend on today's date.
     */
    protected function clockToWallTime(string $offset = '+0 seconds'): void
    {
        $this->clock->modify('@'.(new \DateTimeImmutable($offset))->getTimestamp());
    }
}
