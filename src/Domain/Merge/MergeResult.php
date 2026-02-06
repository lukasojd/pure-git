<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Merge;

final readonly class MergeResult
{
    /**
     * @param list<string> $conflictedPaths
     */
    public function __construct(
        public bool $isConflicted,
        public string $mergedContent,
        public array $conflictedPaths = [],
    ) {
    }

    public static function clean(string $content): self
    {
        return new self(false, $content);
    }

    /**
     * @param list<string> $paths
     */
    public static function conflicted(string $content, array $paths): self
    {
        return new self(true, $content, $paths);
    }
}
