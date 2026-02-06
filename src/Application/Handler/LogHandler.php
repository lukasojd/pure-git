<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Commit;
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
    public function handle(int $maxCount = 20, ?string $fromRef = null): array
    {
        $ref = $fromRef !== null ? RefName::fromString($fromRef) : RefName::head();
        $commitId = $this->repository->refs->resolve($ref);

        $commits = [];
        $seen = [];

        $queue = [$commitId];

        while ($queue !== [] && count($commits) < $maxCount) {
            $id = array_shift($queue);

            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            $object = $this->repository->objects->read($id);
            if (! $object instanceof Commit) {
                continue;
            }

            $commits[] = $object;

            foreach ($object->parents as $parentId) {
                if (! isset($seen[$parentId->hash])) {
                    $queue[] = $parentId;
                }
            }
        }

        return $commits;
    }
}
