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
        // Try as commit hash
        try {
            return ObjectId::fromHex($target);
        } catch (\Throwable) {
            // Not a hash
        }

        if (str_starts_with($target, 'HEAD')) {
            return $this->resolveHeadRelative($target);
        }

        return $this->repository->refs->resolve(RefName::fromString($target));
    }

    private function resolveHeadRelative(string $target): ObjectId
    {
        $steps = $this->parseHeadSteps(substr($target, 4));
        $commitId = $this->repository->refs->resolve(RefName::head());

        return $this->walkBackCommits($commitId, $steps);
    }

    private function parseHeadSteps(string $suffix): int
    {
        if ($suffix === '') {
            return 0;
        }

        if (! str_starts_with($suffix, '~')) {
            return 0;
        }

        $numericPart = substr($suffix, 1);
        if ($numericPart === '') {
            return 1;
        }

        return (int) $numericPart;
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
