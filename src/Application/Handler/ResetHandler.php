<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Index\Index;
use Lukasojd\PureGit\Domain\Index\IndexEntry;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Object\TreeEntry;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Object\CombinedObjectStorage;

final readonly class ResetHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function handle(string $target, ResetMode $mode): void
    {
        $commitId = $this->resolveTarget($target);

        $commit = $this->repository->objects->read($commitId);
        if (! $commit instanceof Commit) {
            throw new PureGitException('Target does not point to a commit');
        }

        $this->moveHead($commitId);

        if ($mode === ResetMode::Mixed || $mode === ResetMode::Hard) {
            $index = new Index();
            $this->populateIndexFromTree($commit->treeId, '', $index);
            $this->repository->index->write($index);
        }

        if ($mode === ResetMode::Hard) {
            $this->resetWorkingTree($commit->treeId);
        }
    }

    private function moveHead(ObjectId $commitId): void
    {
        $head = RefName::head();
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if ($symbolicRef instanceof RefName) {
            $this->repository->refs->updateRef($symbolicRef, $commitId);
        } else {
            $this->repository->refs->updateRef($head, $commitId);
        }
    }

    private function resolveTarget(string $target): ObjectId
    {
        [$base, $steps] = $this->splitRevisionSuffix($target);
        $baseId = $this->resolveBase($base);

        return $this->walkBackCommits($baseId, $steps);
    }

    /**
     * @return array{string, int}
     */
    private function splitRevisionSuffix(string $target): array
    {
        if (preg_match('/^(.+?)~(\d*)$/', $target, $m) === 1) {
            return [$m[1], $m[2] === '' ? 1 : (int) $m[2]];
        }

        if (preg_match('/^(.+?)(\^+)$/', $target, $m) === 1) {
            return [$m[1], strlen($m[2])];
        }

        return [$target, 0];
    }

    private function resolveBase(string $base): ObjectId
    {
        if ($base === 'HEAD') {
            return $this->repository->refs->resolve(RefName::head());
        }

        if (preg_match('/^[0-9a-f]{40}$/', $base) === 1) {
            return ObjectId::fromHex($base);
        }

        $refMatch = $this->tryResolveRef($base);
        if ($refMatch instanceof ObjectId) {
            return $refMatch;
        }

        return $this->resolveShortHash($base);
    }

    private function tryResolveRef(string $name): ?ObjectId
    {
        $candidates = [
            $name,
            'refs/' . $name,
            'refs/heads/' . $name,
            'refs/tags/' . $name,
            'refs/remotes/' . $name,
        ];

        foreach ($candidates as $candidate) {
            try {
                return $this->repository->refs->resolve(RefName::fromString($candidate));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function resolveShortHash(string $prefix): ObjectId
    {
        if (preg_match('/^[0-9a-f]{4,39}$/', $prefix) !== 1) {
            throw new PureGitException(sprintf("fatal: ambiguous argument '%s': unknown revision", $prefix));
        }

        if (! ($this->repository->objects instanceof CombinedObjectStorage)) {
            throw new PureGitException(sprintf("fatal: bad revision '%s'", $prefix));
        }

        $match = $this->repository->objects->findByPrefix($prefix);
        if (! $match instanceof ObjectId) {
            throw new PureGitException(sprintf("fatal: bad revision '%s'", $prefix));
        }

        return $match;
    }

    private function walkBackCommits(ObjectId $commitId, int $steps): ObjectId
    {
        for ($i = 0; $i < $steps; $i++) {
            $commit = $this->repository->objects->read($commitId);
            if (! $commit instanceof Commit || $commit->parents === []) {
                throw new PureGitException(sprintf('Cannot go back %d commits', $steps));
            }
            $commitId = $commit->parents[0];
        }

        return $commitId;
    }

    private function populateIndexFromTree(ObjectId $treeId, string $prefix, Index $index): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;
            $this->populateIndexEntry($entry, $path, $index);
        }
    }

    private function populateIndexEntry(TreeEntry $entry, string $path, Index $index): void
    {
        if ($entry->isTree()) {
            $this->populateIndexFromTree($entry->objectId, $path, $index);
            return;
        }

        $blob = $this->repository->objects->read($entry->objectId);
        $size = $blob instanceof Blob ? strlen($blob->content) : 0;
        $indexEntry = IndexEntry::create($path, $entry->objectId, $entry->mode, $size);
        $index->addEntry($indexEntry);
    }

    private function resetWorkingTree(ObjectId $treeId): void
    {
        $items = $this->repository->filesystem->listDirectory($this->repository->workDir);
        foreach ($items as $item) {
            if ($item === '.git') {
                continue;
            }
            $this->repository->filesystem->delete($this->repository->workDir . '/' . $item);
        }

        $this->writeTreeToWorkDir($treeId, '');
    }

    private function writeTreeToWorkDir(ObjectId $treeId, string $prefix): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;
            $this->writeEntryToWorkDir($entry, $path);
        }
    }

    private function writeEntryToWorkDir(TreeEntry $entry, string $path): void
    {
        $fullPath = $this->repository->workDir . '/' . $path;

        if ($entry->isTree()) {
            $this->repository->filesystem->mkdir($fullPath);
            $this->writeTreeToWorkDir($entry->objectId, $path);
            return;
        }

        $blob = $this->repository->objects->read($entry->objectId);
        if (! $blob instanceof Blob) {
            return;
        }

        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $this->repository->filesystem->write($fullPath, $blob->content);
    }
}
