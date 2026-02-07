<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\Tag;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class ShowHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function handle(?string $target = null): GitObject
    {
        if ($target === null) {
            $headId = $this->repository->refs->resolve(RefName::head());

            return $this->repository->objects->read($headId);
        }

        return $this->resolveTarget($target);
    }

    private function resolveTarget(string $target): GitObject
    {
        // Try as full hex hash
        if ($this->isFullHex($target)) {
            return $this->repository->objects->read(ObjectId::fromHex($target));
        }

        // Try as ref with common prefixes
        $object = $this->tryResolveRef($target);
        if ($object instanceof GitObject) {
            return $this->peelTag($object);
        }

        // Try as short hash prefix
        $object = $this->tryResolvePrefix($target);
        if ($object instanceof GitObject) {
            return $object;
        }

        throw new PureGitException(sprintf('Reference not found: %s', $target));
    }

    private function tryResolveRef(string $target): ?GitObject
    {
        $prefixes = ['', 'refs/', 'refs/heads/', 'refs/tags/', 'refs/remotes/'];

        foreach ($prefixes as $prefix) {
            $ref = RefName::fromString($prefix . $target);
            if ($this->repository->refs->exists($ref)) {
                $id = $this->repository->refs->resolve($ref);

                return $this->repository->objects->read($id);
            }
        }

        return null;
    }

    private function tryResolvePrefix(string $target): ?GitObject
    {
        if (strlen($target) < 4 || ! ctype_xdigit($target)) {
            return null;
        }

        $id = $this->repository->objects->findByPrefix($target);
        if ($id instanceof ObjectId) {
            return $this->repository->objects->read($id);
        }

        return null;
    }

    private function peelTag(GitObject $object): GitObject
    {
        if ($object instanceof Tag) {
            return $this->repository->objects->read($object->targetId);
        }

        return $object;
    }

    private function isFullHex(string $value): bool
    {
        return strlen($value) === 40 && ctype_xdigit($value);
    }
}
