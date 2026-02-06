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
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class CheckoutHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function checkout(string $target): void
    {
        // Try as branch first
        $branchRef = RefName::branch($target);
        if ($this->repository->refs->exists($branchRef)) {
            $commitId = $this->repository->refs->resolve($branchRef);
            $this->updateWorkingTree($commitId);
            $this->repository->refs->updateSymbolicRef(RefName::head(), $branchRef);

            return;
        }

        // Try as a commit hash
        try {
            $commitId = ObjectId::fromHex($target);
            if ($this->repository->objects->exists($commitId)) {
                $this->updateWorkingTree($commitId);
                $this->repository->refs->updateRef(RefName::head(), $commitId);

                return;
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

        $content = $this->findFileInTree($commit->treeId, $path);
        if ($content === null) {
            throw new PureGitException(sprintf('File not found in HEAD: %s', $path));
        }

        $fullPath = $this->repository->workDir . '/' . $path;
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $this->repository->filesystem->write($fullPath, $content);
    }

    private function updateWorkingTree(ObjectId $commitId): void
    {
        $commit = $this->repository->objects->read($commitId);
        if (! $commit instanceof Commit) {
            throw new PureGitException('Target does not point to a commit');
        }

        // Clear current working tree (except .git)
        $this->cleanWorkingTree();

        // Write new tree to working directory and update index
        $index = new Index();
        $this->writeTree($commit->treeId, '', $index);
        $this->repository->index->write($index);
    }

    private function cleanWorkingTree(): void
    {
        $files = $this->repository->filesystem->listDirectory($this->repository->workDir);
        foreach ($files as $file) {
            if ($file === '.git') {
                continue;
            }

            $fullPath = $this->repository->workDir . '/' . $file;
            $this->repository->filesystem->delete($fullPath);
        }
    }

    private function writeTree(ObjectId $treeId, string $prefix, Index $index): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;

            if ($entry->isTree()) {
                $fullDir = $this->repository->workDir . '/' . $path;
                $this->repository->filesystem->mkdir($fullDir);
                $this->writeTree($entry->objectId, $path, $index);
            } else {
                $blob = $this->repository->objects->read($entry->objectId);
                if ($blob instanceof Blob) {
                    $fullPath = $this->repository->workDir . '/' . $path;
                    $dir = dirname($fullPath);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0o777, true);
                    }
                    $this->repository->filesystem->write($fullPath, $blob->content);

                    $indexEntry = IndexEntry::create($path, $entry->objectId, $entry->mode, strlen($blob->content));
                    $index->addEntry($indexEntry);
                }
            }
        }
    }

    private function findFileInTree(ObjectId $treeId, string $path): ?string
    {
        $parts = explode('/', $path);
        $tree = $this->repository->objects->read($treeId);

        if (! $tree instanceof Tree) {
            return null;
        }

        $current = $tree;
        $counter = count($parts);

        for ($i = 0; $i < $counter; $i++) {
            $entry = $current->findEntry($parts[$i]);
            if (! $entry instanceof \Lukasojd\PureGit\Domain\Object\TreeEntry) {
                return null;
            }

            if ($i === count($parts) - 1) {
                // Last part — should be a file
                $blob = $this->repository->objects->read($entry->objectId);
                if ($blob instanceof Blob) {
                    return $blob->content;
                }

                return null;
            }

            // Not the last part — should be a tree
            $subtree = $this->repository->objects->read($entry->objectId);
            if (! $subtree instanceof Tree) {
                return null;
            }
            $current = $subtree;
        }

        return null;
    }
}
