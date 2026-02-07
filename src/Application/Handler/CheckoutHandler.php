<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Index\Index;
use Lukasojd\PureGit\Domain\Index\IndexEntry;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Object\TreeEntry;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class CheckoutHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function checkout(string $target): CheckoutResult
    {
        // Try as branch first
        $branchRef = RefName::branch($target);
        if ($this->repository->refs->exists($branchRef)) {
            return $this->checkoutBranch($branchRef);
        }

        // Try as a commit hash
        try {
            $commitId = ObjectId::fromHex($target);
            if ($this->repository->objects->exists($commitId)) {
                $this->updateWorkingTree($commitId);
                $this->repository->refs->updateRef(RefName::head(), $commitId);

                return CheckoutResult::DetachedHead;
            }
        } catch (\Throwable) {
            // Not a valid hash
        }

        throw new PureGitException(sprintf('Cannot checkout: %s', $target));
    }

    public function restoreFile(string $path): void
    {
        $commitId = $this->repository->refs->resolve(RefName::head());
        $commit = $this->repository->objects->read($commitId);

        if (! $commit instanceof Commit) {
            throw new PureGitException('HEAD does not point to a commit');
        }

        $entry = $this->findEntryInTree($commit->treeId, $path);
        if (! $entry instanceof \Lukasojd\PureGit\Domain\Object\TreeEntry) {
            throw new PureGitException(sprintf('File not found in HEAD: %s', $path));
        }

        $blob = $this->repository->objects->read($entry->objectId);
        if (! $blob instanceof Blob) {
            throw new PureGitException(sprintf('File not found in HEAD: %s', $path));
        }

        $fullPath = $this->repository->workDir . '/' . $path;
        $this->ensureParentDirectory($fullPath);
        $this->repository->filesystem->write($fullPath, $blob->content);

        if ($entry->mode === FileMode::Executable) {
            chmod($fullPath, 0o755);
        }
    }

    public function checkoutNewBranch(string $name, ?string $startPoint = null): CheckoutResult
    {
        $branchRef = RefName::branch($name);

        if ($this->repository->refs->exists($branchRef)) {
            throw new PureGitException(sprintf('A branch named \'%s\' already exists', $name));
        }

        $commitId = $this->resolveStartPoint($startPoint);
        $this->repository->refs->updateRef($branchRef, $commitId);
        $this->updateWorkingTree($commitId);
        $this->repository->refs->updateSymbolicRef(RefName::head(), $branchRef);

        return CheckoutResult::CreatedAndSwitched;
    }

    private function resolveStartPoint(?string $startPoint): ObjectId
    {
        if ($startPoint === null) {
            return $this->repository->refs->resolve(RefName::head());
        }

        $branchRef = RefName::branch($startPoint);
        if ($this->repository->refs->exists($branchRef)) {
            return $this->repository->refs->resolve($branchRef);
        }

        try {
            $commitId = ObjectId::fromHex($startPoint);
            if ($this->repository->objects->exists($commitId)) {
                return $commitId;
            }
        } catch (\Throwable) {
            // Not a valid hash
        }

        throw new PureGitException(sprintf('Not a valid start point: %s', $startPoint));
    }

    private function checkoutBranch(RefName $branchRef): CheckoutResult
    {
        $currentBranch = $this->repository->refs->getSymbolicRef(RefName::head());

        if ($currentBranch instanceof RefName && $currentBranch->equals($branchRef)) {
            return CheckoutResult::AlreadyOnBranch;
        }

        $commitId = $this->repository->refs->resolve($branchRef);
        $this->updateWorkingTree($commitId);
        $this->repository->refs->updateSymbolicRef(RefName::head(), $branchRef);

        return CheckoutResult::SwitchedToBranch;
    }

    private function updateWorkingTree(ObjectId $commitId): void
    {
        $commit = $this->repository->objects->read($commitId);
        if (! $commit instanceof Commit) {
            throw new PureGitException('Target does not point to a commit');
        }

        $oldPaths = $this->collectCurrentIndexPaths();
        $index = new Index();
        $this->writeTree($commit->treeId, '', $index, $oldPaths);
        $this->removeObsoleteFiles($oldPaths, $index);
        $this->repository->index->write($index);
    }

    /**
     * @return array<string, ObjectId>
     */
    private function collectCurrentIndexPaths(): array
    {
        try {
            $currentIndex = $this->repository->index->read();
        } catch (\Throwable) {
            return [];
        }

        $paths = [];
        foreach ($currentIndex->getEntries() as $path => $entry) {
            $paths[$path] = $entry->objectId;
        }

        return $paths;
    }

    /**
     * @param array<string, ObjectId> $oldPaths
     */
    private function writeTree(ObjectId $treeId, string $prefix, Index $index, array $oldPaths): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;
            $this->writeTreeEntry($entry, $path, $index, $oldPaths);
        }
    }

    /**
     * @param array<string, ObjectId> $oldPaths
     */
    private function writeTreeEntry(TreeEntry $entry, string $path, Index $index, array $oldPaths): void
    {
        if ($entry->isTree()) {
            $fullDir = $this->repository->workDir . '/' . $path;
            $this->repository->filesystem->mkdir($fullDir);
            $this->writeTree($entry->objectId, $path, $index, $oldPaths);
            return;
        }

        $fullPath = $this->repository->workDir . '/' . $path;

        if ($this->canReuseFile($path, $entry->objectId, $fullPath, $oldPaths)) {
            $stat = stat($fullPath);
            if ($stat !== false) {
                $index->addEntry(IndexEntry::createFromStat($path, $entry->objectId, $entry->mode, $stat));
                return;
            }
        }

        $this->writeFileToWorkingTree($entry, $fullPath, $path, $index);
    }

    /**
     * @param array<string, ObjectId> $oldPaths
     */
    private function canReuseFile(string $path, ObjectId $objectId, string $fullPath, array $oldPaths): bool
    {
        return isset($oldPaths[$path]) && $oldPaths[$path]->equals($objectId) && file_exists($fullPath);
    }

    private function writeFileToWorkingTree(TreeEntry $entry, string $fullPath, string $path, Index $index): void
    {
        $blob = $this->repository->objects->read($entry->objectId);
        if (! $blob instanceof Blob) {
            return;
        }

        $this->ensureParentDirectory($fullPath);
        $this->repository->filesystem->write($fullPath, $blob->content);

        if ($entry->mode === FileMode::Executable) {
            chmod($fullPath, 0o755);
        }

        $stat = stat($fullPath);
        $indexEntry = $stat !== false
            ? IndexEntry::createFromStat($path, $entry->objectId, $entry->mode, $stat)
            : IndexEntry::create($path, $entry->objectId, $entry->mode, strlen($blob->content));
        $index->addEntry($indexEntry);
    }

    /**
     * @param array<string, ObjectId> $oldPaths
     */
    private function removeObsoleteFiles(array $oldPaths, Index $newIndex): void
    {
        $dirsToCheck = [];
        foreach (array_keys($oldPaths) as $path) {
            if ($newIndex->hasEntry($path)) {
                continue;
            }

            $fullPath = $this->repository->workDir . '/' . $path;
            if (file_exists($fullPath)) {
                unlink($fullPath);
                $dirsToCheck[dirname($path)] = true;
            }
        }

        foreach (array_keys($dirsToCheck) as $dir) {
            $this->removeEmptyParentChain($dir);
        }
    }

    private function removeEmptyParentChain(string $relativePath): void
    {
        while ($relativePath !== '' && $relativePath !== '.') {
            $fullDir = $this->repository->workDir . '/' . $relativePath;
            if (! is_dir($fullDir)) {
                break;
            }

            $items = @scandir($fullDir);
            if ($items === false || count($items) > 2) {
                break;
            }

            rmdir($fullDir);
            $relativePath = dirname($relativePath);
        }
    }

    private function findEntryInTree(ObjectId $treeId, string $path): ?TreeEntry
    {
        $parts = explode('/', $path);

        return $this->traverseTreePath($treeId, $parts, 0);
    }

    /**
     * @param list<string> $parts
     */
    private function traverseTreePath(ObjectId $treeId, array $parts, int $depth): ?TreeEntry
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return null;
        }

        $entry = $tree->findEntry($parts[$depth]);
        if (! $entry instanceof TreeEntry) {
            return null;
        }

        if ($depth === count($parts) - 1) {
            return $entry;
        }

        return $this->traverseTreePath($entry->objectId, $parts, $depth + 1);
    }

    private function ensureParentDirectory(string $fullPath): void
    {
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
    }
}
