<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

final readonly class FetchResult
{
    /**
     * @param list<RefUpdate> $refUpdates
     */
    public function __construct(
        public int $newObjects,
        public int $updatedRefs,
        public bool $upToDate,
        public string $remoteUrl = '',
        public array $refUpdates = [],
    ) {
    }
}
