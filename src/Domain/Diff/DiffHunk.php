<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

final readonly class DiffHunk
{
    /**
     * @param list<DiffLine> $lines
     */
    public function __construct(
        public int $oldStart,
        public int $oldCount,
        public int $newStart,
        public int $newCount,
        public array $lines,
    ) {
    }

    public function header(): string
    {
        return sprintf('@@ -%d,%d +%d,%d @@', $this->oldStart, $this->oldCount, $this->newStart, $this->newCount);
    }
}
