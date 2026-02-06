<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

final readonly class PushResult
{
    /**
     * @param list<PushRefUpdate> $refUpdates
     */
    public function __construct(
        public bool $upToDate,
        public int $objectsSent,
        public string $remoteUrl,
        public array $refUpdates,
    ) {
    }
}
