<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Ref\RefName;

final readonly class StatusHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    /**
     * @return array{staged: array<string, FileStatus>, unstaged: array<string, FileStatus>, untracked: list<string>}
     */
    public function handle(): array
    {
        $index = $this->repository->index->read();

        // Get HEAD tree entries
        $headEntries = $this->getHeadTreeEntries();

        // Get working tree files
        $workingFiles = $this->getWorkingTreeFiles();

        $staged = [];
        $unstaged = [];
        $untracked = [];

        $indexEntries = $index->getEntries();

        // Compare index vs HEAD (staged changes)
        foreach ($indexEntries as $path => $entry) {
            if (! isset($headEntries[$path])) {
                $staged[$path] = FileStatus::Added;
            } elseif (! $entry->objectId->equals($headEntries[$path])) {
                $staged[$path] = FileStatus::Modified;
            }
        }

        // Files in HEAD but not in index (staged deletions)
        foreach (array_keys($headEntries) as $path) {
            if (! isset($indexEntries[$path])) {
                $staged[$path] = FileStatus::Deleted;
            }
        }

        // Compare working tree vs index (unstaged changes)
        foreach ($indexEntries as $path => $entry) {
            $fullPath = $this->repository->workDir . '/' . $path;
            if (! file_exists($fullPath)) {
                $unstaged[$path] = FileStatus::Deleted;
            } else {
                $content = $this->repository->filesystem->read($fullPath);
                $workingBlob = new Blob($content);
                if (! $workingBlob->getId()->equals($entry->objectId)) {
                    $unstaged[$path] = FileStatus::Modified;
                }
            }
        }

        // Untracked files
        foreach ($workingFiles as $file) {
            if (! isset($indexEntries[$file])) {
                $untracked[] = $file;
            }
        }

        ksort($staged);
        ksort($unstaged);
        sort($untracked);

        return [
            'staged' => $staged,
            'unstaged' => $unstaged,
            'untracked' => $untracked,
        ];
    }

    /**
     * @return array<string, \Lukasojd\PureGit\Domain\Object\ObjectId>
     */
    private function getHeadTreeEntries(): array
    {
        $entries = [];

        try {
            $headId = $this->repository->refs->resolve(RefName::head());
            $commit = $this->repository->objects->read($headId);

            if ($commit instanceof \Lukasojd\PureGit\Domain\Object\Commit) {
                $this->collectTreeEntries($commit->treeId, '', $entries);
            }
        } catch (\Throwable) {
            // No HEAD yet
        }

        return $entries;
    }

    /**
     * @param array<string, \Lukasojd\PureGit\Domain\Object\ObjectId> $entries
     */
    private function collectTreeEntries(\Lukasojd\PureGit\Domain\Object\ObjectId $treeId, string $prefix, array &$entries): void
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
    private function getWorkingTreeFiles(): array
    {
        $files = $this->repository->filesystem->listFilesRecursive($this->repository->workDir);

        // Filter out .git directory
        return array_values(array_filter(
            $files,
            static fn (string $f): bool => ! str_starts_with($f, '.git/') && $f !== '.git',
        ));
    }
}
