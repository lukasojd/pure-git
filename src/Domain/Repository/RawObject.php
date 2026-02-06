<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Repository;

use Lukasojd\PureGit\Domain\Object\ObjectType;

final readonly class RawObject
{
    public function __construct(
        public ObjectType $type,
        public int $size,
        public string $data,
    ) {
    }
}
