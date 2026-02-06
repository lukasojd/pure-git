<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class ShowHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function handle(?string $target = null): GitObject
    {
        if ($target !== null) {
            try {
                $id = ObjectId::fromHex($target);

                return $this->repository->objects->read($id);
            } catch (\Throwable) {
                // Try as ref
                $ref = RefName::fromString($target);
                $id = $this->repository->refs->resolve($ref);

                return $this->repository->objects->read($id);
            }
        }

        $headId = $this->repository->refs->resolve(RefName::head());

        return $this->repository->objects->read($headId);
    }
}
