<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use DateTimeImmutable;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\MergeConflictException;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Index\IndexEntry;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Merge\ThreeWayMerge;

final readonly class MergeHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function handle(string $branchName): ObjectId
    {
        $oursId = $this->repository->refs->resolve(RefName::head());
        $theirsRef = RefName::branch($branchName);
        $theirsId = $this->repository->refs->resolve($theirsRef);

        // Check if already up to date
        if ($oursId->equals($theirsId)) {
            throw new PureGitException('Already up to date');
        }

        // Find merge base
        $baseId = $this->findMergeBase($oursId, $theirsId);

        // Fast-forward check
        if ($baseId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId && $baseId->equals($oursId)) {
            return $this->fastForward($theirsId);
        }

        // 3-way merge
        return $this->threeWayMerge($oursId, $theirsId, $baseId, $branchName);
    }

    private function fastForward(ObjectId $targetId): ObjectId
    {
        $head = RefName::head();
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if ($symbolicRef instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            $this->repository->refs->updateRef($symbolicRef, $targetId);
        } else {
            $this->repository->refs->updateRef($head, $targetId);
        }

        // Update working tree
        $commit = $this->repository->objects->read($targetId);
        if ($commit instanceof Commit) {
            // Rebuild index and working tree from the new commit
            $index = new \Lukasojd\PureGit\Domain\Index\Index();
            $this->writeTreeToIndex($commit->treeId, '', $index);
            $this->repository->index->write($index);

            $this->writeTreeToWorkDir($commit->treeId, '');
        }

        return $targetId;
    }

    private function threeWayMerge(ObjectId $oursId, ObjectId $theirsId, ?ObjectId $baseId, string $branchName): ObjectId
    {
        $oursCommit = $this->repository->objects->read($oursId);
        $theirsCommit = $this->repository->objects->read($theirsId);

        if (! $oursCommit instanceof Commit || ! $theirsCommit instanceof Commit) {
            throw new PureGitException('Merge targets must be commits');
        }

        $oursFiles = $this->collectFiles($oursCommit->treeId);
        $theirsFiles = $this->collectFiles($theirsCommit->treeId);
        $baseFiles = $baseId instanceof \Lukasojd\PureGit\Domain\Object\ObjectId ? $this->collectFilesFromCommit($baseId) : [];

        $mergeStrategy = new ThreeWayMerge();
        $conflictedPaths = [];
        $mergedFiles = [];

        $allPaths = array_unique(array_merge(array_keys($oursFiles), array_keys($theirsFiles), array_keys($baseFiles)));
        sort($allPaths);

        foreach ($allPaths as $path) {
            $baseContent = $baseFiles[$path] ?? '';
            $oursContent = $oursFiles[$path] ?? '';
            $theirsContent = $theirsFiles[$path] ?? '';

            if ($oursContent === $theirsContent) {
                $mergedFiles[$path] = $oursContent;
                continue;
            }

            if ($baseContent === $oursContent) {
                $mergedFiles[$path] = $theirsContent;
                continue;
            }

            if ($baseContent === $theirsContent) {
                $mergedFiles[$path] = $oursContent;
                continue;
            }

            // Both changed differently — 3-way merge
            $result = $mergeStrategy->merge(
                $this->splitLines($baseContent),
                $this->splitLines($oursContent),
                $this->splitLines($theirsContent),
            );

            $mergedFiles[$path] = $result->mergedContent;

            if ($result->isConflicted) {
                $conflictedPaths[] = $path;
            }
        }

        if ($conflictedPaths !== []) {
            // Write conflicted files to working tree
            foreach ($mergedFiles as $path => $content) {
                $fullPath = $this->repository->workDir . '/' . $path;
                $dir = dirname($fullPath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0o777, true);
                }
                $this->repository->filesystem->write($fullPath, $content);
            }

            throw new MergeConflictException($conflictedPaths);
        }

        // Write merged files, create blobs, update index
        $index = $this->repository->index->read();

        foreach ($mergedFiles as $path => $content) {
            $blob = new Blob($content);
            $this->repository->objects->write($blob);

            $fullPath = $this->repository->workDir . '/' . $path;
            $dir = dirname($fullPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            $this->repository->filesystem->write($fullPath, $content);

            $entry = IndexEntry::create($path, $blob->getId(), \Lukasojd\PureGit\Domain\Object\FileMode::Regular, strlen($content));
            $index->addEntry($entry);
        }

        $this->repository->index->write($index);

        // Create merge commit
        $message = sprintf('Merge branch \'%s\'', $branchName);
        $now = new DateTimeImmutable();
        $person = new PersonInfo('PureGit User', 'user@puregit.local', $now);

        // Build tree from index
        $treeId = $this->buildTreeFromIndex();

        $mergeCommit = new Commit($treeId, [$oursId, $theirsId], $person, $person, $message);
        $this->repository->objects->write($mergeCommit);

        $head = RefName::head();
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if ($symbolicRef instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            $this->repository->refs->updateRef($symbolicRef, $mergeCommit->getId());
        } else {
            $this->repository->refs->updateRef($head, $mergeCommit->getId());
        }

        return $mergeCommit->getId();
    }

    private function findMergeBase(ObjectId $a, ObjectId $b): ?ObjectId
    {
        $ancestorsA = $this->collectAncestors($a);
        $queue = [$b];
        $seen = [];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id->hash])) {
                continue;
            }
            $seen[$id->hash] = true;

            if (isset($ancestorsA[$id->hash])) {
                return $id;
            }

            $object = $this->repository->objects->read($id);
            if ($object instanceof Commit) {
                foreach ($object->parents as $parentId) {
                    $queue[] = $parentId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private function collectAncestors(ObjectId $id): array
    {
        $ancestors = [];
        $queue = [$id];

        while ($queue !== []) {
            $currentId = array_shift($queue);

            if (isset($ancestors[$currentId->hash])) {
                continue;
            }
            $ancestors[$currentId->hash] = true;

            $object = $this->repository->objects->read($currentId);
            if ($object instanceof Commit) {
                foreach ($object->parents as $parentId) {
                    $queue[] = $parentId;
                }
            }
        }

        return $ancestors;
    }

    /**
     * @return array<string, string>
     */
    private function collectFiles(ObjectId $treeId): array
    {
        $files = [];
        $this->collectFilesRecursive($treeId, '', $files);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function collectFilesFromCommit(ObjectId $commitId): array
    {
        $commit = $this->repository->objects->read($commitId);
        if (! $commit instanceof Commit) {
            return [];
        }

        return $this->collectFiles($commit->treeId);
    }

    /**
     * @param array<string, string> $files
     */
    private function collectFilesRecursive(ObjectId $treeId, string $prefix, array &$files): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;

            if ($entry->isTree()) {
                $this->collectFilesRecursive($entry->objectId, $path, $files);
            } else {
                $blob = $this->repository->objects->read($entry->objectId);
                if ($blob instanceof Blob) {
                    $files[$path] = $blob->content;
                }
            }
        }
    }

    private function writeTreeToIndex(ObjectId $treeId, string $prefix, \Lukasojd\PureGit\Domain\Index\Index $index): void
    {
        $tree = $this->repository->objects->read($treeId);
        if (! $tree instanceof Tree) {
            return;
        }

        foreach ($tree->entries as $entry) {
            $path = $prefix === '' ? $entry->name : $prefix . '/' . $entry->name;

            if ($entry->isTree()) {
                $this->writeTreeToIndex($entry->objectId, $path, $index);
            } else {
                $blob = $this->repository->objects->read($entry->objectId);
                $size = $blob instanceof Blob ? strlen($blob->content) : 0;
                $indexEntry = IndexEntry::create($path, $entry->objectId, $entry->mode, $size);
                $index->addEntry($indexEntry);
            }
        }
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

    private function buildTreeFromIndex(): ObjectId
    {
        $commitHandler = new CommitHandler($this->repository);
        $index = $this->repository->index->read();

        return $commitHandler->buildTree($index);
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
