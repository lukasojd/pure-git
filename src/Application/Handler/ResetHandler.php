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

enum ResetMode: string
{
    case Soft = 'soft';
    case Mixed = 'mixed';
    case Hard = 'hard';
}

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

        // Move HEAD
        $head = RefName::head();
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if ($symbolicRef instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            $this->repository->refs->updateRef($symbolicRef, $commitId);
        } else {
            $this->repository->refs->updateRef($head, $commitId);
        }

        if ($mode === ResetMode::Mixed || $mode === ResetMode::Hard) {
            // Reset index
            $index = new Index();
            $this->populateIndexFromTree($commit->treeId, '', $index);
            $this->repository->index->write($index);
        }

        if ($mode === ResetMode::Hard) {
            // Reset working tree
            $this->resetWorkingTree($commit->treeId);
        }
    }

    private function resolveTarget(string $target): ObjectId
    {
        // Try as commit hash
        try {
            return ObjectId::fromHex($target);
        } catch (\Throwable) {
            // Not a hash
        }

        // Try HEAD~N
        if (str_starts_with($target, 'HEAD')) {
            $suffix = substr($target, 4);
            $steps = 0;

            if ($suffix === '') {
                $steps = 0;
            } elseif (str_starts_with($suffix, '~')) {
                $steps = (int) substr($suffix, 1);
                if ($steps === 0 && strlen($suffix) === 1) {
                    $steps = 1;
                }
            }

            $commitId = $this->repository->refs->resolve(RefName::head());
            for ($i = 0; $i < $steps; $i++) {
                $commit = $this->repository->objects->read($commitId);
                if (! $commit instanceof Commit || $commit->parents === []) {
                    throw new PureGitException(sprintf('Cannot go back %d commits', $steps));
                }
                $commitId = $commit->parents[0];
            }

            return $commitId;
        }

        // Try as ref
        return $this->repository->refs->resolve(RefName::fromString($target));
    }

    private function populateIndexFromTree(ObjectId $treeId, string $prefix, Index $index): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;
            if ($entry->isTree()) {
                $this->populateIndexFromTree($entry->objectId, $path, $index);
            } else {
                $blob = $this->repository->objects->read($entry->objectId);
                $size = $blob instanceof Blob ? strlen($blob->content) : 0;
                $indexEntry = IndexEntry::create($path, $entry->objectId, $entry->mode, $size);
                $index->addEntry($indexEntry);
            }
        }
    }

    private function resetWorkingTree(ObjectId $treeId): void
    {
        // Clean working tree (except .git)
        $items = $this->repository->filesystem->listDirectory($this->repository->workDir);
        foreach ($items as $item) {
            if ($item === '.git') {
                continue;
            }
            $this->repository->filesystem->delete($this->repository->workDir . '/' . $item);
        }

        // Write tree
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
            $fullPath = $this->repository->workDir . '/' . $path;

            if ($entry->isTree()) {
                $this->repository->filesystem->mkdir($fullPath);
                $this->writeTreeToWorkDir($entry->objectId, $path);
            } else {
                $blob = $this->repository->objects->read($entry->objectId);
                if ($blob instanceof Blob) {
                    $dir = dirname($fullPath);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0o777, true);
                    }
                    $this->repository->filesystem->write($fullPath, $blob->content);
                }
            }
        }
    }
}
