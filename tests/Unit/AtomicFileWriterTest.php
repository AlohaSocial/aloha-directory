<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Lists\AtomicFileWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AtomicFileWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/aloha-atomic-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0o700);
        (new Filesystem())->remove($this->dir);
    }

    public function testWritesContentsWithDefaultMode(): void
    {
        $path = $this->dir.'/servers.json';
        AtomicFileWriter::write($path, "{\"a\":1}\n");

        self::assertSame("{\"a\":1}\n", file_get_contents($path));
        clearstatcache();
        self::assertSame(0o644, fileperms($path) & 0o777);
        self::assertSame([], $this->leftovers(), 'no temporary file is left behind');
    }

    public function testHonoursTheRequestedMode(): void
    {
        $path = $this->dir.'/secret';
        AtomicFileWriter::write($path, 'x', 0o600);
        clearstatcache();
        self::assertSame(0o600, fileperms($path) & 0o777);
    }

    public function testCreatesMissingDirectories(): void
    {
        $path = $this->dir.'/lists/v1/deep/keys.json';
        AtomicFileWriter::write($path, 'k');
        self::assertSame('k', file_get_contents($path));
    }

    public function testReplacesAnExistingFileByRenameNotInPlace(): void
    {
        $path = $this->dir.'/servers.json';
        AtomicFileWriter::write($path, 'old version');
        $handle = fopen($path, 'r');
        self::assertNotFalse($handle);
        $inodeBefore = fstat($handle)['ino'];

        AtomicFileWriter::write($path, 'new');

        // A reader that opened the old file keeps seeing the complete old version.
        self::assertSame('old version', stream_get_contents($handle));
        fclose($handle);
        self::assertSame('new', file_get_contents($path));
        clearstatcache();
        self::assertNotSame($inodeBefore, stat($path)['ino'], 'the file was replaced, not rewritten');
        self::assertSame([], $this->leftovers());
    }

    public function testWritesEmptyAndBinaryContents(): void
    {
        AtomicFileWriter::write($this->dir.'/empty', '');
        self::assertSame('', file_get_contents($this->dir.'/empty'));

        $bytes = random_bytes(70_000);
        AtomicFileWriter::write($this->dir.'/bin', $bytes);
        self::assertSame($bytes, file_get_contents($this->dir.'/bin'));
    }

    public function testFailsWhenTheDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->dir.'/file', 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create directory');
        try {
            @AtomicFileWriter::write($this->dir.'/file/child.json', 'x');
        } finally {
            self::assertSame('x', file_get_contents($this->dir.'/file'));
        }
    }

    public function testFailsWithoutTouchingTheTargetWhenTheDirectoryIsNotWritable(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root can write anywhere');
        }
        $path = $this->dir.'/servers.json';
        AtomicFileWriter::write($path, 'old');
        chmod($this->dir, 0o500);

        try {
            @AtomicFileWriter::write($path, 'new');
            self::fail('Writing into a read-only directory succeeded.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Cannot create', $e->getMessage());
        } finally {
            chmod($this->dir, 0o700);
        }
        self::assertSame('old', file_get_contents($path));
    }

    public function testFailsWhenTheTargetIsADirectory(): void
    {
        mkdir($this->dir.'/servers.json');

        try {
            @AtomicFileWriter::write($this->dir.'/servers.json', 'x');
            self::fail('A directory was replaced by a file.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Cannot move', $e->getMessage());
        }
        self::assertDirectoryExists($this->dir.'/servers.json');
        self::assertSame([], $this->leftovers(), 'the temporary file is removed after a failed rename');
    }

    /**
     * @return list<string>
     */
    private function leftovers(): array
    {
        return array_values(array_filter(scandir($this->dir) ?: [], static fn (string $f): bool => str_ends_with($f, '.tmp')));
    }
}
