<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class LogHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    /**
     * @return list<Commit>
     */
    public function handle(int $maxCount = 20, ?string $fromRef = null, bool $all = false): array
    {
        $startIds = $all ? $this->allRefIds() : [$this->repository->refs->resolve(
            $fromRef !== null ? RefName::fromString($fromRef) : RefName::head(),
        )];

        $commits = [];
        $seen = [];
        $queue = $startIds;

        while ($queue !== [] && count($commits) < $maxCount) {
            $id = array_shift($queue);
            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            $commit = $this->readCommit($id);
            if (! $commit instanceof Commit) {
                continue;
            }

            $commits[] = $commit;
            $this->enqueueUnseen($commit->parents, $seen, $queue);
        }

        usort($commits, static fn (Commit $a, Commit $b): int => $b->author->timestamp->getTimestamp() - $a->author->timestamp->getTimestamp());

        return $commits;
    }

    /**
     * @return list<ObjectId>
     */
    private function allRefIds(): array
    {
        $allRefs = $this->repository->refs->listRefs('refs/');
        $ids = [];
        $seen = [];

        foreach ($allRefs as $id) {
            if (! isset($seen[$id->hash])) {
                $seen[$id->hash] = true;
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function readCommit(ObjectId $id): ?Commit
    {
        $object = $this->repository->objects->read($id);

        return $object instanceof Commit ? $object : null;
    }

    /**
     * @param list<ObjectId> $parents
     * @param array<string, true> $seen
     * @param list<ObjectId> $queue
     */
    private function enqueueUnseen(array $parents, array $seen, array &$queue): void
    {
        foreach ($parents as $parentId) {
            if (! isset($seen[$parentId->hash])) {
                $queue[] = $parentId;
            }
        }
    }
}
