<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\CommitGraph;

use Lukasojd\PureGit\Domain\Object\ObjectId;

interface CommitGraphInterface
{
    public function hasCommit(ObjectId $id): bool;

    /**
     * @return list<ObjectId>
     */
    public function getParents(ObjectId $id): array;

    public function getGeneration(ObjectId $id): int;

    public function getTimestamp(ObjectId $id): int;

    public function getCommitCount(): int;
}
