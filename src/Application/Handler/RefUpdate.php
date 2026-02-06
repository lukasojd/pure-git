<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

final readonly class RefUpdate
{
    public function __construct(
        public string $remoteName,
        public string $localName,
        public ?string $oldHash,
        public string $newHash,
    ) {
    }

    public function isNew(): bool
    {
        return $this->oldHash === null;
    }

    public function isTag(): bool
    {
        return str_starts_with($this->remoteName, 'refs/tags/');
    }
}
