<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\DiffAlgorithm;
use Lukasojd\PureGit\Domain\Diff\FileDiff;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class DiffHandler
{
    public function __construct(
        private Repository $repository,
        private DiffAlgorithm $diffAlgorithm,
    ) {
    }

    /**
     * @return list<FileDiff>
     */
    public function diffWorkingVsIndex(): array
    {
        $index = $this->repository->index->read();
        $diffs = [];

        foreach ($index->getEntries() as $path => $entry) {
            $fullPath = $this->repository->workDir . '/' . $path;

            if (! file_exists($fullPath)) {
                $diffs[] = new FileDiff($path, FileStatus::Deleted, []);
                continue;
            }

            $workingContent = $this->repository->filesystem->read($fullPath);
            $indexBlob = $this->repository->objects->read($entry->objectId);

            if (! $indexBlob instanceof Blob) {
                continue;
            }

            if ($workingContent === $indexBlob->content) {
                continue;
            }

            $oldLines = $this->splitLines($indexBlob->content);
            $newLines = $this->splitLines($workingContent);
            $hunks = $this->diffAlgorithm->diff($oldLines, $newLines);

            $diffs[] = new FileDiff($path, FileStatus::Modified, $hunks);
        }

        return $diffs;
    }

    /**
     * @return list<FileDiff>
     */
    public function diffIndexVsHead(): array
    {
        $index = $this->repository->index->read();
        $headEntries = $this->getHeadTreeEntries();
        $diffs = [];

        foreach ($index->getEntries() as $path => $entry) {
            if (! isset($headEntries[$path])) {
                $diffs[] = new FileDiff($path, FileStatus::Added, []);
                continue;
            }

            if ($entry->objectId->equals($headEntries[$path])) {
                continue;
            }

            $headBlob = $this->repository->objects->read($headEntries[$path]);
            $indexBlob = $this->repository->objects->read($entry->objectId);

            if (! $headBlob instanceof Blob || ! $indexBlob instanceof Blob) {
                continue;
            }

            $oldLines = $this->splitLines($headBlob->content);
            $newLines = $this->splitLines($indexBlob->content);
            $hunks = $this->diffAlgorithm->diff($oldLines, $newLines);

            $diffs[] = new FileDiff($path, FileStatus::Modified, $hunks);
        }

        // Deleted from HEAD
        foreach (array_keys($headEntries) as $path) {
            if (! $index->hasEntry($path)) {
                $diffs[] = new FileDiff($path, FileStatus::Deleted, []);
            }
        }

        return $diffs;
    }

    /**
     * @return list<FileDiff>
     */
    public function diffCommits(ObjectId $oldCommitId, ObjectId $newCommitId): array
    {
        $oldEntries = $this->collectCommitEntries($oldCommitId);
        $newEntries = $this->collectCommitEntries($newCommitId);

        $allPaths = array_unique(array_merge(array_keys($oldEntries), array_keys($newEntries)));
        sort($allPaths);

        $diffs = [];
        foreach ($allPaths as $path) {
            $diff = $this->diffPath($path, $oldEntries[$path] ?? null, $newEntries[$path] ?? null);
            if ($diff instanceof FileDiff) {
                $diffs[] = $diff;
            }
        }

        return $diffs;
    }

    private function diffPath(string $path, ?ObjectId $oldId, ?ObjectId $newId): ?FileDiff
    {
        if (! $oldId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId && $newId instanceof ObjectId) {
            return $this->diffAddedFile($path, $newId);
        }

        if ($oldId instanceof ObjectId && ! $newId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId) {
            return $this->diffDeletedFile($path, $oldId);
        }

        if ($oldId instanceof ObjectId && $newId instanceof ObjectId && ! $oldId->equals($newId)) {
            return $this->diffModifiedFile($path, $oldId, $newId);
        }

        return null;
    }

    private function diffAddedFile(string $path, ObjectId $newId): ?FileDiff
    {
        $blob = $this->repository->objects->read($newId);
        if (! $blob instanceof Blob) {
            return null;
        }

        $hunks = $this->diffAlgorithm->diff([], $this->splitLines($blob->content));

        return new FileDiff($path, FileStatus::Added, $hunks);
    }

    private function diffDeletedFile(string $path, ObjectId $oldId): ?FileDiff
    {
        $blob = $this->repository->objects->read($oldId);
        if (! $blob instanceof Blob) {
            return null;
        }

        $hunks = $this->diffAlgorithm->diff($this->splitLines($blob->content), []);

        return new FileDiff($path, FileStatus::Deleted, $hunks);
    }

    private function diffModifiedFile(string $path, ObjectId $oldId, ObjectId $newId): ?FileDiff
    {
        $oldBlob = $this->repository->objects->read($oldId);
        $newBlob = $this->repository->objects->read($newId);

        if (! $oldBlob instanceof Blob || ! $newBlob instanceof Blob) {
            return null;
        }

        $hunks = $this->diffAlgorithm->diff(
            $this->splitLines($oldBlob->content),
            $this->splitLines($newBlob->content),
        );

        return new FileDiff($path, FileStatus::Modified, $hunks);
    }

    /**
     * @return array<string, ObjectId>
     */
    private function collectCommitEntries(ObjectId $commitId): array
    {
        $commit = $this->repository->objects->read($commitId);
        $entries = [];

        if ($commit instanceof Commit) {
            $this->collectTreeEntries($commit->treeId, '', $entries);
        }

        return $entries;
    }

    /**
     * @return array<string, ObjectId>
     */
    private function getHeadTreeEntries(): array
    {
        $entries = [];

        try {
            $headId = $this->repository->refs->resolve(RefName::head());
            $commit = $this->repository->objects->read($headId);

            if ($commit instanceof Commit) {
                $this->collectTreeEntries($commit->treeId, '', $entries);
            }
        } catch (\Throwable) {
            // No HEAD
        }

        return $entries;
    }

    /**
     * @param array<string, ObjectId> $entries
     */
    private function collectTreeEntries(ObjectId $treeId, string $prefix, array &$entries): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;
            if ($entry->isTree()) {
                $this->collectTreeEntries($entry->objectId, $path, $entries);
            } else {
                $entries[$path] = $entry->objectId;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $content): array
    {
        if ($content === '') {
            return [];
        }

        return explode("\n", $content);
    }
}
