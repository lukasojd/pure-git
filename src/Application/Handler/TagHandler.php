<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use DateTimeImmutable;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\ObjectType;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Object\Tag;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class TagHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function createLightweight(string $name, ?ObjectId $target = null): void
    {
        $ref = RefName::tag($name);

        if ($this->repository->refs->exists($ref)) {
            throw new PureGitException(sprintf('Tag already exists: %s', $name));
        }

        $targetId = $target ?? $this->repository->refs->resolve(RefName::head());
        $this->repository->refs->updateRef($ref, $targetId);
    }

    public function createAnnotated(string $name, string $message, ?ObjectId $target = null, ?PersonInfo $tagger = null): void
    {
        $ref = RefName::tag($name);

        if ($this->repository->refs->exists($ref)) {
            throw new PureGitException(sprintf('Tag already exists: %s', $name));
        }

        $targetId = $target ?? $this->repository->refs->resolve(RefName::head());

        $tagger ??= new PersonInfo('PureGit User', 'user@puregit.local', new DateTimeImmutable());

        $tag = new Tag($targetId, ObjectType::Commit, $name, $tagger, $message);
        $this->repository->objects->write($tag);

        $this->repository->refs->updateRef($ref, $tag->getId());
    }

    /**
     * @return array<string, ObjectId>
     */
    public function list(): array
    {
        return $this->repository->refs->listRefs('refs/tags/');
    }

    public function delete(string $name): void
    {
        $ref = RefName::tag($name);
        $this->repository->refs->deleteRef($ref);
    }
}
