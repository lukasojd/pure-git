<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Integration;

use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Infrastructure\Object\DeltaDecoder;
use Lukasojd\PureGit\Infrastructure\Object\DeltaEncoder;
use Lukasojd\PureGit\Infrastructure\Object\PackfileReader;
use Lukasojd\PureGit\Infrastructure\Object\PackfileWriter;
use Lukasojd\PureGit\Infrastructure\Object\PackIndexReader;
use Lukasojd\PureGit\Infrastructure\Object\PackWriterConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Benchmark gate tests: assert performance characteristics.
 *
 * These tests verify that delta encoding produces beneficial results,
 * pack files are correct, and operations complete within acceptable bounds.
 */
final class BenchmarkGateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pure-git-bench-' . getmypid();
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
    public function deltaCompressionRatioForSimilarObjects(): void
    {
        $baseContent = str_repeat("shared content line that appears in many versions\n", 200);
        $blobs = [];
        for ($i = 0; $i < 30; $i++) {
            $blobs[] = new Blob($baseContent . sprintf("variation %d\n", $i));
        }

        $noDeltaPath = $this->tmpDir . '/nodelta.pack';
        $deltaPath = $this->tmpDir . '/delta.pack';

        $writer = new PackfileWriter();
        $writer->write($blobs, $noDeltaPath, new PackWriterConfig(enableDelta: false));
        $writer->write($blobs, $deltaPath, new PackWriterConfig(enableDelta: true));

        $noDeltaSize = filesize($noDeltaPath);
        $deltaSize = filesize($deltaPath);
        $this->assertNotFalse($noDeltaSize);
        $this->assertNotFalse($deltaSize);

        // Delta pack must be at least 50% smaller for highly similar objects
        $ratio = $deltaSize / $noDeltaSize;
        $this->assertLessThan(0.50, $ratio, sprintf(
            'Delta compression ratio %.1f%% is not good enough (expected < 50%%)',
            $ratio * 100,
        ));
    }

    #[Test]
    public function deltaRoundtripCorrectness(): void
    {
        $base = str_repeat("base content with repeating patterns here\n", 300);
        $target = str_repeat("base content with repeating patterns here\n", 150)
            . "MODIFIED SECTION IN THE MIDDLE\n"
            . str_repeat("base content with repeating patterns here\n", 150);

        $delta = DeltaEncoder::encode($base, $target);
        $this->assertNotNull($delta, 'Delta should be produced for similar content');
        $this->assertLessThan(strlen($target), strlen($delta), 'Delta should be smaller than target');

        $result = DeltaDecoder::apply($base, $delta);
        $this->assertSame($target, $result, 'Delta roundtrip must produce identical result');
    }

    #[Test]
    public function packRoundtripWithDeltaChains(): void
    {
        // Create objects that form delta chains
        $baseContent = str_repeat("line of code in a PHP file with some content\n", 200);
        $blobs = [];
        for ($i = 0; $i < 20; $i++) {
            // Each blob differs slightly from the previous
            $content = $baseContent . str_repeat(sprintf("extra line %d\n", $i), $i + 1);
            $blobs[] = new Blob($content);
        }

        $packPath = $this->tmpDir . '/chain.pack';
        $writer = new PackfileWriter();
        $config = new PackWriterConfig(maxDepth: 10, enableDelta: true, generateIndex: true);
        $writer->write($blobs, $packPath, $config);

        $indexReader = new PackIndexReader($this->tmpDir . '/chain.idx');
        $packReader = new PackfileReader($packPath, $indexReader);

        foreach ($blobs as $blob) {
            $raw = $packReader->readObject($blob->getId());
            $this->assertSame(
                $blob->serialize(),
                $raw->data,
                sprintf('Object %s data mismatch after pack roundtrip', $blob->getId()->short()),
            );
        }
    }

    #[Test]
    public function packWritePerformance(): void
    {
        $blobs = [];
        for ($i = 0; $i < 100; $i++) {
            $blobs[] = new Blob(str_repeat(sprintf("content for blob %d\n", $i), 50));
        }

        $packPath = $this->tmpDir . '/perf.pack';
        $writer = new PackfileWriter();
        $config = new PackWriterConfig(enableDelta: false);

        $start = hrtime(true);
        $writer->write($blobs, $packPath, $config);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        // Pack writing 100 objects should complete within 500ms
        $this->assertLessThan(500, $elapsed, sprintf(
            'Pack write took %.1f ms (expected < 500ms)',
            $elapsed,
        ));
    }

    #[Test]
    public function deltaEncoderPerformance(): void
    {
        $base = str_repeat("line of text for performance testing delta encoder\n", 500);
        $target = str_repeat("line of text for performance testing delta encoder\n", 250)
            . "INSERTED BLOCK\n"
            . str_repeat("line of text for performance testing delta encoder\n", 250);

        $start = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            DeltaEncoder::encode($base, $target);
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        // 100 delta encodes of ~25KB objects should complete within 2 seconds
        $this->assertLessThan(2000, $elapsed, sprintf(
            '100 delta encodes took %.1f ms (expected < 2000ms)',
            $elapsed,
        ));
    }

    #[Test]
    public function memoryUsageForLargePack(): void
    {
        $blobs = [];
        for ($i = 0; $i < 200; $i++) {
            $blobs[] = new Blob(str_repeat(sprintf("blob %d data\n", $i), 100));
        }

        $packPath = $this->tmpDir . '/mem.pack';
        $writer = new PackfileWriter();

        $memBefore = memory_get_usage(true);
        $writer->write($blobs, $packPath, new PackWriterConfig(enableDelta: false));
        $memAfter = memory_get_usage(true);

        $memDelta = ($memAfter - $memBefore) / 1024 / 1024;

        // Writing 200 objects should not consume more than 50MB additional memory
        $this->assertLessThan(50, $memDelta, sprintf(
            'Pack write consumed %.1f MB (expected < 50MB)',
            $memDelta,
        ));
    }
}
