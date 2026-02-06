<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Domain\Repository;

use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;

interface RefStorageInterface
{
    public function resolve(RefName $ref): ObjectId;

    public function updateRef(RefName $ref, ObjectId $id): void;

    public function deleteRef(RefName $ref): void;

    public function exists(RefName $ref): bool;

    /**
     * @return array<string, ObjectId> map of ref name to object id
     */
    public function listRefs(string $prefix = 'refs/'): array;

    public function getSymbolicRef(RefName $ref): ?RefName;

    public function updateSymbolicRef(RefName $ref, RefName $target): void;
}
