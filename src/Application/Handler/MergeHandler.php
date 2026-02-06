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
use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\PersonInfo;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Object\TreeEntry;
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

        if ($oursId->equals($theirsId)) {
            throw new PureGitException('Already up to date');
        }

        $resolver = new MergeBaseResolver($this->repository->objects);
        $baseId = $resolver->findMergeBase($oursId, $theirsId);

        if ($baseId instanceof ObjectId && $baseId->equals($oursId)) {
            return $this->fastForward($theirsId);
        }

        return $this->threeWayMerge($oursId, $theirsId, $baseId, $branchName);
    }

    private function fastForward(ObjectId $targetId): ObjectId
    {
        $this->updateHeadRef($targetId);

        $commit = $this->repository->objects->read($targetId);
        if ($commit instanceof Commit) {
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

        $mergedFiles = $this->computeMergedFiles($oursCommit, $theirsCommit, $baseId);

        return $this->finalizeMerge($mergedFiles, $oursId, $theirsId, $branchName);
    }

    /**
     * @return array{files: array<string, string>, conflicted: list<string>}
     */
    private function computeMergedFiles(Commit $oursCommit, Commit $theirsCommit, ?ObjectId $baseId): array
    {
        $oursFiles = $this->collectFiles($oursCommit->treeId);
        $theirsFiles = $this->collectFiles($theirsCommit->treeId);
        $baseFiles = $baseId instanceof ObjectId ? $this->collectFilesFromCommit($baseId) : [];

        $mergeStrategy = new ThreeWayMerge();
        $conflictedPaths = [];
        $mergedFiles = [];

        $allPaths = array_unique(array_merge(array_keys($oursFiles), array_keys($theirsFiles), array_keys($baseFiles)));
        sort($allPaths);

        foreach ($allPaths as $path) {
            $resolved = $this->resolvePathMerge(
                $baseFiles[$path] ?? '',
                $oursFiles[$path] ?? '',
                $theirsFiles[$path] ?? '',
                $mergeStrategy,
            );

            $mergedFiles[$path] = $resolved['content'];

            if ($resolved['conflicted']) {
                $conflictedPaths[] = $path;
            }
        }

        return [
            'files' => $mergedFiles,
            'conflicted' => $conflictedPaths,
        ];
    }

    /**
     * @return array{content: string, conflicted: bool}
     */
    private function resolvePathMerge(string $baseContent, string $oursContent, string $theirsContent, ThreeWayMerge $mergeStrategy): array
    {
        if ($oursContent === $theirsContent) {
            return [
                'content' => $oursContent,
                'conflicted' => false,
            ];
        }

        if ($baseContent === $oursContent) {
            return [
                'content' => $theirsContent,
                'conflicted' => false,
            ];
        }

        if ($baseContent === $theirsContent) {
            return [
                'content' => $oursContent,
                'conflicted' => false,
            ];
        }

        $result = $mergeStrategy->merge(
            $this->splitLines($baseContent),
            $this->splitLines($oursContent),
            $this->splitLines($theirsContent),
        );

        return [
            'content' => $result->mergedContent,
            'conflicted' => $result->isConflicted,
        ];
    }

    /**
     * @param array{files: array<string, string>, conflicted: list<string>} $mergedFiles
     */
    private function finalizeMerge(array $mergedFiles, ObjectId $oursId, ObjectId $theirsId, string $branchName): ObjectId
    {
        if ($mergedFiles['conflicted'] !== []) {
            $this->writeMergedFilesToWorkDir($mergedFiles['files']);

            throw new MergeConflictException($mergedFiles['conflicted']);
        }

        $this->writeMergedFilesToIndexAndWorkDir($mergedFiles['files']);

        return $this->createMergeCommit($oursId, $theirsId, $branchName);
    }

    /**
     * @param array<string, string> $files
     */
    private function writeMergedFilesToWorkDir(array $files): void
    {
        foreach ($files as $path => $content) {
            $this->ensureDirectoryAndWriteFile($path, $content);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function writeMergedFilesToIndexAndWorkDir(array $files): void
    {
        $index = $this->repository->index->read();

        foreach ($files as $path => $content) {
            $blob = new Blob($content);
            $this->repository->objects->write($blob);
            $this->ensureDirectoryAndWriteFile($path, $content);

            $entry = IndexEntry::create($path, $blob->getId(), FileMode::Regular, strlen($content));
            $index->addEntry($entry);
        }

        $this->repository->index->write($index);
    }

    private function ensureDirectoryAndWriteFile(string $path, string $content): void
    {
        $fullPath = $this->repository->workDir . '/' . $path;
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $this->repository->filesystem->write($fullPath, $content);
    }

    private function createMergeCommit(ObjectId $oursId, ObjectId $theirsId, string $branchName): ObjectId
    {
        $message = sprintf('Merge branch \'%s\'', $branchName);
        $now = new DateTimeImmutable();
        $person = new PersonInfo('PureGit User', 'user@puregit.local', $now);

        $treeId = $this->buildTreeFromIndex();
        $mergeCommit = new Commit($treeId, [$oursId, $theirsId], $person, $person, $message);
        $this->repository->objects->write($mergeCommit);

        $this->updateHeadRef($mergeCommit->getId());

        return $mergeCommit->getId();
    }

    private function updateHeadRef(ObjectId $commitId): void
    {
        $head = RefName::head();
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if ($symbolicRef instanceof RefName) {
            $this->repository->refs->updateRef($symbolicRef, $commitId);
        } else {
            $this->repository->refs->updateRef($head, $commitId);
        }
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
            $this->collectTreeEntryContent($entry, $path, $files);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function collectTreeEntryContent(TreeEntry $entry, string $path, array &$files): void
    {
        if ($entry->isTree()) {
            $this->collectFilesRecursive($entry->objectId, $path, $files);
            return;
        }

        $blob = $this->repository->objects->read($entry->objectId);
        if ($blob instanceof Blob) {
            $files[$path] = $blob->content;
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
            $this->writeEntryToIndex($entry, $path, $index);
        }
    }

    private function writeEntryToIndex(TreeEntry $entry, string $path, \Lukasojd\PureGit\Domain\Index\Index $index): void
    {
        if ($entry->isTree()) {
            $this->writeTreeToIndex($entry->objectId, $path, $index);
            return;
        }

        $blob = $this->repository->objects->read($entry->objectId);
        $size = $blob instanceof Blob ? strlen($blob->content) : 0;
        $indexEntry = IndexEntry::create($path, $entry->objectId, $entry->mode, $size);
        $index->addEntry($indexEntry);
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

        $this->ensureDirectoryAndWriteFile($path, $blob->content);
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
