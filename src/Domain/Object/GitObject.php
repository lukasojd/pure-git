<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Object;

interface GitObject
{
    public function getId(): ObjectId;

    public function getType(): ObjectType;

    public function serialize(): string;
}
