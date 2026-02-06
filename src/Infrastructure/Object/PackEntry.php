<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

final readonly class PackEntry
{
    public function __construct(
        public int $packType,
        public string $rawData,
        public string $compressedData,
        public int $packOffset,
        public ?string $baseHash,
        public string $hash,
        public int $depth,
    ) {
    }
}
