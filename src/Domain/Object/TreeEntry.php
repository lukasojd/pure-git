<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

final readonly class TreeEntry
{
    public function __construct(
        public FileMode $mode,
        public string $name,
        public ObjectId $objectId,
    ) {
    }

    public function isTree(): bool
    {
        return $this->mode === FileMode::Directory;
    }

    public function isBlob(): bool
    {
        return $this->mode === FileMode::Regular || $this->mode === FileMode::Executable;
    }
}
