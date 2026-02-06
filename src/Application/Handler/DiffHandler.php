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
