<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Infrastructure\Object\PackfileReader;
use Lukasojd\PureGit\Infrastructure\Object\PackfileWriter;
use Lukasojd\PureGit\Infrastructure\Object\PackIndexReader;
use Lukasojd\PureGit\Infrastructure\Object\PackWriterConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackfileWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pure-git-test-' . getmypid();
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function writeAndReadBackWithoutDelta(): void
    {
        $blobs = $this->createDistinctBlobs(10);
        $packPath = $this->tmpDir . '/test.pack';

        $writer = new PackfileWriter();
        $config = new PackWriterConfig(enableDelta: false, generateIndex: true);
        $writer->write($blobs, $packPath, $config);

        $this->assertFileExists($packPath);
        $this->assertFileExists($this->tmpDir . '/test.idx');

        $indexReader = new PackIndexReader($this->tmpDir . '/test.idx');
        $packReader = new PackfileReader($packPath, $indexReader);

        foreach ($blobs as $blob) {
            $raw = $packReader->readObject($blob->getId());
            $this->assertSame($blob->serialize(), $raw->data);
        }
    }

    #[Test]
    public function writeAndReadBackWithDelta(): void
    {
        $blobs = $this->createSimilarBlobs(20);
        $packPath = $this->tmpDir . '/delta.pack';

        $writer = new PackfileWriter();
        $config = new PackWriterConfig(enableDelta: true, generateIndex: true);
        $writer->write($blobs, $packPath, $config);

        $this->assertFileExists($packPath);

        $indexReader = new PackIndexReader($this->tmpDir . '/delta.idx');
        $packReader = new PackfileReader($packPath, $indexReader);

        foreach ($blobs as $blob) {
            $raw = $packReader->readObject($blob->getId());
            $this->assertSame($blob->serialize(), $raw->data);
        }
    }

    #[Test]
    public function deltaPackIsSmallerThanNonDeltaPack(): void
    {
        $blobs = $this->createSimilarBlobs(30);

        $noDeltaPath = $this->tmpDir . '/nodelta.pack';
        $deltaPath = $this->tmpDir . '/delta.pack';

        $writer = new PackfileWriter();
        $writer->write($blobs, $noDeltaPath, new PackWriterConfig(enableDelta: false));
        $writer->write($blobs, $deltaPath, new PackWriterConfig(enableDelta: true));

        $noDeltaSize = filesize($noDeltaPath);
        $deltaSize = filesize($deltaPath);

        $this->assertNotFalse($noDeltaSize);
        $this->assertNotFalse($deltaSize);
        $this->assertLessThan($noDeltaSize, $deltaSize, 'Delta pack should be smaller than non-delta pack');
    }

    #[Test]
    public function indexContainsAllObjects(): void
    {
        $blobs = $this->createDistinctBlobs(15);
        $packPath = $this->tmpDir . '/indexed.pack';

        $writer = new PackfileWriter();
        $writer->write($blobs, $packPath, new PackWriterConfig(generateIndex: true));

        $indexReader = new PackIndexReader($this->tmpDir . '/indexed.idx');
        $allIds = $indexReader->getAllIds();

        $this->assertCount(count($blobs), $allIds);

        foreach ($blobs as $blob) {
            $this->assertTrue($indexReader->hasObject($blob->getId()));
        }
    }

    #[Test]
    public function deltaChainDepthIsRespected(): void
    {
        // Create many similar blobs to force deep chains
        $blobs = $this->createSimilarBlobs(10);
        $packPath = $this->tmpDir . '/shallow.pack';

        $writer = new PackfileWriter();
        $config = new PackWriterConfig(maxDepth: 2, enableDelta: true, generateIndex: true);
        $writer->write($blobs, $packPath, $config);

        // All objects must be readable (chains won't exceed depth)
        $indexReader = new PackIndexReader($this->tmpDir . '/shallow.idx');
        $packReader = new PackfileReader($packPath, $indexReader);

        foreach ($blobs as $blob) {
            $raw = $packReader->readObject($blob->getId());
            $this->assertSame($blob->serialize(), $raw->data);
        }
    }

    #[Test]
    public function deltaReuseRoundtripCorrectness(): void
    {
        $blobs = $this->createSimilarBlobs(20);

        // Write the first pack (source for reuse)
        $sourcePath = $this->tmpDir . '/source.pack';
        $writer = new PackfileWriter();
        $writer->write($blobs, $sourcePath, new PackWriterConfig(enableDelta: true, generateIndex: true));

        $sourceIndex = new PackIndexReader($this->tmpDir . '/source.idx');
        $sourceReader = new PackfileReader($sourcePath, $sourceIndex);

        // Write a second pack reusing deltas from the first
        $reusePath = $this->tmpDir . '/reuse.pack';
        $writer->write($blobs, $reusePath, new PackWriterConfig(enableDelta: true, generateIndex: true), [$sourceReader]);

        $reuseIndex = new PackIndexReader($this->tmpDir . '/reuse.idx');
        $reuseReader = new PackfileReader($reusePath, $reuseIndex);

        foreach ($blobs as $blob) {
            $raw = $reuseReader->readObject($blob->getId());
            $this->assertSame($blob->serialize(), $raw->data, sprintf(
                'Reuse roundtrip mismatch for %s',
                $blob->getId()->short(),
            ));
        }
    }

    #[Test]
    public function deltaReuseFallsBackWhenBaseMissing(): void
    {
        $blobs = $this->createSimilarBlobs(10);

        // Write a source pack with all blobs
        $sourcePath = $this->tmpDir . '/full.pack';
        $writer = new PackfileWriter();
        $writer->write($blobs, $sourcePath, new PackWriterConfig(enableDelta: true, generateIndex: true));

        $sourceIndex = new PackIndexReader($this->tmpDir . '/full.idx');
        $sourceReader = new PackfileReader($sourcePath, $sourceIndex);

        // Write a pack with only a subset (base objects may be missing)
        $subset = array_slice($blobs, 5);
        $subsetPath = $this->tmpDir . '/subset.pack';
        $writer->write($subset, $subsetPath, new PackWriterConfig(enableDelta: true, generateIndex: true), [$sourceReader]);

        $subsetIndex = new PackIndexReader($this->tmpDir . '/subset.idx');
        $subsetReader = new PackfileReader($subsetPath, $subsetIndex);

        foreach ($subset as $blob) {
            $raw = $subsetReader->readObject($blob->getId());
            $this->assertSame($blob->serialize(), $raw->data);
        }
    }

    #[Test]
    public function deltaReuseRespectsDepthLimit(): void
    {
        $blobs = $this->createSimilarBlobs(15);

        // Write source with deep chains allowed
        $sourcePath = $this->tmpDir . '/deep.pack';
        $writer = new PackfileWriter();
        $writer->write($blobs, $sourcePath, new PackWriterConfig(maxDepth: 50, enableDelta: true, generateIndex: true));

        $sourceIndex = new PackIndexReader($this->tmpDir . '/deep.idx');
        $sourceReader = new PackfileReader($sourcePath, $sourceIndex);

        // Rewrite with shallow depth limit — reuse should be rejected for deep chains
        $shallowPath = $this->tmpDir . '/shallow-reuse.pack';
        $writer->write($blobs, $shallowPath, new PackWriterConfig(maxDepth: 1, enableDelta: true, generateIndex: true), [$sourceReader]);

        $shallowIndex = new PackIndexReader($this->tmpDir . '/shallow-reuse.idx');
        $shallowReader = new PackfileReader($shallowPath, $shallowIndex);

        foreach ($blobs as $blob) {
            $raw = $shallowReader->readObject($blob->getId());
            $this->assertSame($blob->serialize(), $raw->data);
        }
    }

    /**
     * @return list<Blob>
     */
    private function createDistinctBlobs(int $count): array
    {
        $blobs = [];
        for ($i = 0; $i < $count; $i++) {
            $blobs[] = new Blob(sprintf("distinct content %d: %s\n", $i, str_repeat(chr(65 + ($i % 26)), 200)));
        }

        return $blobs;
    }

    /**
     * @return list<Blob>
     */
    private function createSimilarBlobs(int $count): array
    {
        $baseContent = str_repeat("This is a shared line of content that repeats.\n", 100);
        $blobs = [];
        for ($i = 0; $i < $count; $i++) {
            $blobs[] = new Blob($baseContent . sprintf("Unique line %d\n", $i));
        }

        return $blobs;
    }
}
