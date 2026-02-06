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

    /**
     * Read raw object with data potentially truncated to just the header.
     * For commit/tag: data may end at the first "\n\n". Other types: same as readRaw().
     */
    public function readRawHeader(ObjectId $id): RawObject;

    /**
     * Same as readRawHeader but accepts a 20-byte binary hash, avoiding ObjectId creation overhead.
     *
     * @param string $binHash 20-byte raw hash
     */
    public function readRawHeaderByBinary(string $binHash): RawObject;
}
