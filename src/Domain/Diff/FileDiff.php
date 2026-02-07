<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Diff;

use Lukasojd\PureGit\Domain\Object\ObjectId;

final readonly class FileDiff
{
    /**
     * @param list<DiffHunk> $hunks
     */
    public function __construct(
        public string $path,
        public FileStatus $status,
        public array $hunks,
        public ?ObjectId $oldId = null,
        public ?ObjectId $newId = null,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->hunks !== [];
    }
}
