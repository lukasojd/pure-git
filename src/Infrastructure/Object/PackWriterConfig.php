<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

final readonly class PackWriterConfig
{
    public function __construct(
        public int $window = 10,
        public int $maxDepth = 50,
        public bool $enableDelta = true,
        public bool $generateIndex = false,
        public int $compressionLevel = 6,
        public int $depthPenaltyFactor = 50,
    ) {
    }
}
