<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

final readonly class PushRefUpdate
{
    public function __construct(
        public string $refName,
        public ?string $oldHash,
        public string $newHash,
        public string $status,
    ) {
    }

    public function isOk(): bool
    {
        return str_starts_with($this->status, 'ok');
    }
}
