<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

final readonly class FileDiff
{
    /**
     * @param list<DiffHunk> $hunks
     */
    public function __construct(
        public string $path,
        public FileStatus $status,
        public array $hunks,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->hunks !== [];
    }
}
