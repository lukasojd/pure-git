<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Repository;

use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectId;

interface ObjectStorageInterface
{
    public function read(ObjectId $id): GitObject;

    public function write(GitObject $object): ObjectId;

    public function exists(ObjectId $id): bool;

    public function readRaw(ObjectId $id): RawObject;
}
