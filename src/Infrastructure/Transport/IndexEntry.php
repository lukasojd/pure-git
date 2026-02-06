<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

final readonly class IndexEntry
{
    public function __construct(
        public string $hash,
        public int $offset,
        public int $crc32,
    ) {
    }
}
