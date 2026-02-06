<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

enum ObjectType: string
{
    case Blob = 'blob';
    case Tree = 'tree';
    case Commit = 'commit';
    case Tag = 'tag';

    public function toBytes(): string
    {
        return $this->value;
    }
}
