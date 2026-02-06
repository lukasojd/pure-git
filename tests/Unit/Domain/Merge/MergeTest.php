<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Domain\Merge;

use Lukasojd\PureGit\Infrastructure\Merge\ThreeWayMerge;
use PHPUnit\Framework\TestCase;

final class MergeTest extends TestCase
{
    private ThreeWayMerge $merger;

    protected function setUp(): void
    {
        $this->merger = new ThreeWayMerge();
    }

    public function testNoConflict(): void
    {
        $base = ['line1', 'line2', 'line3'];
        $ours = ['line1', 'modified', 'line3'];
        $theirs = ['line1', 'line2', 'line3', 'added'];

        $result = $this->merger->merge($base, $ours, $theirs);

        self::assertFalse($result->isConflicted);
    }

    public function testConflict(): void
    {
        $base = ['line1', 'line2', 'line3'];
        $ours = ['line1', 'ours-change', 'line3'];
        $theirs = ['line1', 'theirs-change', 'line3'];

        $result = $this->merger->merge($base, $ours, $theirs);

        self::assertTrue($result->isConflicted);
        self::assertStringContainsString('<<<<<<< ours', $result->mergedContent);
        self::assertStringContainsString('>>>>>>> theirs', $result->mergedContent);
    }

    public function testIdenticalChanges(): void
    {
        $base = ['line1', 'line2'];
        $ours = ['line1', 'same-change'];
        $theirs = ['line1', 'same-change'];

        $result = $this->merger->merge($base, $ours, $theirs);

        self::assertFalse($result->isConflicted);
    }
}
