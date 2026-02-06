<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

final readonly class CommitGraphResult
{
    public function __construct(
        public int $commitCount,
        public float $elapsedMs,
        public int $fileSizeBytes,
    ) {
    }
}
