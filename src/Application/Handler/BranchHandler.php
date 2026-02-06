<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class BranchHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function create(string $name, ?ObjectId $startPoint = null): void
    {
        $ref = RefName::branch($name);

        if ($this->repository->refs->exists($ref)) {
            throw new PureGitException(sprintf('Branch already exists: %s', $name));
        }

        $target = $startPoint ?? $this->repository->refs->resolve(RefName::head());
        $this->repository->refs->updateRef($ref, $target);
    }

    /**
     * @return array<string, ObjectId>
     */
    public function list(): array
    {
        return $this->repository->refs->listRefs('refs/heads/');
    }

    public function delete(string $name): void
    {
        $ref = RefName::branch($name);

        $currentBranch = $this->getCurrentBranch();
        if ($currentBranch instanceof \Lukasojd\PureGit\Domain\Ref\RefName && $currentBranch->equals($ref)) {
            throw new PureGitException(sprintf('Cannot delete the currently checked out branch: %s', $name));
        }

        $this->repository->refs->deleteRef($ref);
    }

    public function getCurrentBranch(): ?RefName
    {
        return $this->repository->refs->getSymbolicRef(RefName::head());
    }
}
