<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Diff;

use Lukasojd\PureGit\Domain\Diff\DiffLineType;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    private MyersDiffAlgorithm $differ;

    protected function setUp(): void
    {
        $this->differ = new MyersDiffAlgorithm();
    }

    public function testIdenticalContent(): void
    {
        $lines = ['line1', 'line2', 'line3'];
        $hunks = $this->differ->diff($lines, $lines);
        self::assertSame([], $hunks);
    }

    public function testAddedLines(): void
    {
        $old = ['line1', 'line3'];
        $new = ['line1', 'line2', 'line3'];
        $hunks = $this->differ->diff($old, $new);

        self::assertNotEmpty($hunks);
        $hasAdded = false;
        foreach ($hunks as $hunk) {
            foreach ($hunk->lines as $line) {
                if ($line->type === DiffLineType::Added) {
                    $hasAdded = true;
                }
            }
        }
        self::assertTrue($hasAdded);
    }

    public function testRemovedLines(): void
    {
        $old = ['line1', 'line2', 'line3'];
        $new = ['line1', 'line3'];
        $hunks = $this->differ->diff($old, $new);

        self::assertNotEmpty($hunks);
        $hasRemoved = false;
        foreach ($hunks as $hunk) {
            foreach ($hunk->lines as $line) {
                if ($line->type === DiffLineType::Removed) {
                    $hasRemoved = true;
                }
            }
        }
        self::assertTrue($hasRemoved);
    }

    public function testEmptyToContent(): void
    {
        $hunks = $this->differ->diff([], ['line1', 'line2']);
        self::assertNotEmpty($hunks);
    }

    public function testContentToEmpty(): void
    {
        $hunks = $this->differ->diff(['line1', 'line2'], []);
        self::assertNotEmpty($hunks);
    }

    public function testBothEmpty(): void
    {
        $hunks = $this->differ->diff([], []);
        self::assertSame([], $hunks);
    }
}
