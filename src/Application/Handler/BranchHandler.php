<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigWriter;

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

    public function rename(string $oldName, string $newName): void
    {
        $oldRef = RefName::branch($oldName);
        $newRef = RefName::branch($newName);

        if (! $this->repository->refs->exists($oldRef)) {
            throw new PureGitException(sprintf('Branch not found: %s', $oldName));
        }

        if ($this->repository->refs->exists($newRef)) {
            throw new PureGitException(sprintf('Branch already exists: %s', $newName));
        }

        $id = $this->repository->refs->resolve($oldRef);
        $this->repository->refs->updateRef($newRef, $id);
        $this->repository->refs->deleteRef($oldRef);

        // Update HEAD if renaming the current branch
        $currentBranch = $this->getCurrentBranch();
        if ($currentBranch instanceof RefName && $currentBranch->equals($oldRef)) {
            $this->repository->refs->updateSymbolicRef(RefName::head(), $newRef);
        }

        // Migrate tracking config
        $this->migrateTrackingConfig($oldName, $newName);
    }

    /**
     * @return array<string, ObjectId>
     */
    public function listRemote(): array
    {
        return $this->repository->refs->listRefs('refs/remotes/');
    }

    public function setUpstreamTo(string $upstream, ?string $branchName = null): void
    {
        if ($branchName === null) {
            $current = $this->getCurrentBranch();
            if (! $current instanceof RefName) {
                throw new PureGitException('HEAD is not on a branch');
            }
            $branchName = $current->shortName();
        }

        if (! str_contains($upstream, '/')) {
            throw new PureGitException(sprintf('Not a valid upstream: %s', $upstream));
        }

        $parts = explode('/', $upstream, 2);
        $configPath = $this->repository->gitDir . '/config';
        $section = 'branch "' . $branchName . '"';
        $writer = new GitConfigWriter();
        $writer->set($configPath, $section, 'remote', $parts[0]);
        $writer->set($configPath, $section, 'merge', 'refs/heads/' . $parts[1]);
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

    public function unsetUpstream(?string $branchName = null): void
    {
        if ($branchName === null) {
            $current = $this->getCurrentBranch();
            if (! $current instanceof RefName) {
                throw new PureGitException('HEAD is not on a branch');
            }
            $branchName = $current->shortName();
        }

        $configPath = $this->repository->gitDir . '/config';
        $section = 'branch "' . $branchName . '"';
        $writer = new GitConfigWriter();
        $writer->unsetKey($configPath, $section, 'remote');
        $writer->unsetKey($configPath, $section, 'merge');
    }

    public function getTrackingInfo(?RefName $branch = null): ?TrackingInfo
    {
        $branch ??= $this->getCurrentBranch();
        if (! $branch instanceof RefName || ! $branch->isBranch()) {
            return null;
        }

        $config = new GitConfigReader($this->repository->gitDir . '/config');
        $upstreamRefPath = $config->getUpstreamRef($branch->shortName());

        if ($upstreamRefPath === null) {
            return null;
        }

        $upstreamRef = RefName::fromString($upstreamRefPath);
        if (! $this->repository->refs->exists($upstreamRef)) {
            return new TrackingInfo(
                upstream: $upstreamRef->shortName(),
                ahead: 0,
                behind: 0,
                gone: true,
            );
        }

        $localId = $this->repository->refs->resolve($branch);
        $remoteId = $this->repository->refs->resolve($upstreamRef);

        [$ahead, $behind] = $this->countAheadBehind($localId, $remoteId);

        return new TrackingInfo(
            upstream: $upstreamRef->shortName(),
            ahead: $ahead,
            behind: $behind,
        );
    }

    /**
     * @return array{int, int} [ahead, behind]
     */
    private function countAheadBehind(ObjectId $localId, ObjectId $remoteId): array
    {
        if ($localId->equals($remoteId)) {
            return [0, 0];
        }

        // BFS from both sides to find all reachable commits
        $localReachable = $this->collectAncestors($localId);
        $remoteReachable = $this->collectAncestors($remoteId);

        $ahead = count(array_diff_key($localReachable, $remoteReachable));
        $behind = count(array_diff_key($remoteReachable, $localReachable));

        return [$ahead, $behind];
    }

    /**
     * @return array<string, bool>
     */
    private function collectAncestors(ObjectId $startId): array
    {
        /** @var array<string, bool> $visited */
        $visited = [
            $startId->hash => true,
        ];
        $queue = new \SplQueue();
        $queue->enqueue($startId);

        while (! $queue->isEmpty()) {
            /** @var ObjectId $current */
            $current = $queue->dequeue();
            $commit = $this->repository->objects->read($current);

            if (! $commit instanceof Commit) {
                continue;
            }

            foreach ($commit->parents as $parentId) {
                if (! isset($visited[$parentId->hash])) {
                    $visited[$parentId->hash] = true;
                    $queue->enqueue($parentId);
                }
            }
        }

        return $visited;
    }

    private function migrateTrackingConfig(string $oldName, string $newName): void
    {
        $configPath = $this->repository->gitDir . '/config';
        $config = new GitConfigReader($configPath);
        $oldSection = 'branch "' . $oldName . '"';
        $remote = $config->get($oldSection, 'remote');
        $merge = $config->get($oldSection, 'merge');

        if ($remote === null || $merge === null) {
            return;
        }

        $writer = new GitConfigWriter();
        $newSection = 'branch "' . $newName . '"';
        $writer->set($configPath, $newSection, 'remote', $remote);
        $writer->set($configPath, $newSection, 'merge', $merge);
        $writer->unsetKey($configPath, $oldSection, 'remote');
        $writer->unsetKey($configPath, $oldSection, 'merge');
    }
}
