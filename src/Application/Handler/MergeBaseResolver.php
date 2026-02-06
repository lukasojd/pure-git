<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Repository\ObjectStorageInterface;

final readonly class MergeBaseResolver
{
    public function __construct(
        private ObjectStorageInterface $objects,
    ) {
    }

    public function findMergeBase(ObjectId $a, ObjectId $b): ?ObjectId
    {
        $ancestorsA = $this->collectAncestors($a);
        $queue = [$b];
        $seen = [];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            if (isset($ancestorsA[$id->hash])) {
                return $id;
            }

            $object = $this->objects->read($id);
            if ($object instanceof Commit) {
                foreach ($object->parents as $parentId) {
                    $queue[] = $parentId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private function collectAncestors(ObjectId $id): array
    {
        $ancestors = [];
        $queue = [$id];

        while ($queue !== []) {
            $currentId = array_shift($queue);

            if (isset($ancestors[$currentId->hash])) {
                continue;
            }
            $ancestors[$currentId->hash] = true;

            $object = $this->objects->read($currentId);
            if ($object instanceof Commit) {
                foreach ($object->parents as $parentId) {
                    $queue[] = $parentId;
                }
            }
        }

        return $ancestors;
    }
}
