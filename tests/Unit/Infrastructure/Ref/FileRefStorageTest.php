<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Ref;

use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Ref\FileRefStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileRefStorageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pure-git-ref-test-' . getmypid();
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir . '/refs/heads', 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function packedRefsCacheAvoidsDuplicateParsing(): void
    {
        $hash = str_repeat('ab', 20);
        $packedPath = $this->tmpDir . '/packed-refs';
        file_put_contents($packedPath, "# pack-refs\n{$hash} refs/heads/main\n");

        $storage = new FileRefStorage($this->tmpDir);

        // First call: resolves from packed-refs
        $id1 = $storage->resolve(RefName::branch('main'));
        $this->assertSame($hash, $id1->hash);

        // Second call: should use cache (same mtime)
        $id2 = $storage->resolve(RefName::branch('main'));
        $this->assertSame($hash, $id2->hash);

        // listRefs also uses the same cache
        $refs = $storage->listRefs('refs/heads/');
        $this->assertCount(1, $refs);
        $this->assertSame($hash, $refs['refs/heads/main']->hash);
    }

    #[Test]
    public function packedRefsCacheInvalidatesOnMtimeChange(): void
    {
        $hash1 = str_repeat('aa', 20);
        $hash2 = str_repeat('bb', 20);
        $packedPath = $this->tmpDir . '/packed-refs';

        file_put_contents($packedPath, "{$hash1} refs/heads/main\n");
        // Set mtime to past
        touch($packedPath, time() - 10);

        $storage = new FileRefStorage($this->tmpDir);
        $id1 = $storage->resolve(RefName::branch('main'));
        $this->assertSame($hash1, $id1->hash);

        // Update file with new content and new mtime
        file_put_contents($packedPath, "{$hash2} refs/heads/main\n");
        clearstatcache(true, $packedPath);

        $id2 = $storage->resolve(RefName::branch('main'));
        $this->assertSame($hash2, $id2->hash);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
