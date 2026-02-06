<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Object\Tree;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Merge\ThreeWayMerge;

final readonly class RebaseHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function rebase(ObjectId $upstreamId): RebaseResult
    {
        $headId = $this->repository->refs->resolve(RefName::head());

        $resolver = new MergeBaseResolver($this->repository->objects);
        $baseId = $resolver->findMergeBase($headId, $upstreamId);

        if ($baseId instanceof ObjectId && $baseId->equals($headId)) {
            $this->updateHead($upstreamId);

            return new RebaseResult(replayedCommits: 0, newHeadId: $upstreamId);
        }

        if ($baseId instanceof ObjectId && $baseId->equals($upstreamId)) {
            return new RebaseResult(replayedCommits: 0, newHeadId: $headId);
        }

        $localCommits = $this->collectLocalCommits($headId, $baseId);
        $currentTip = $upstreamId;

        foreach ($localCommits as $commitId) {
            $currentTip = $this->cherryPick($commitId, $currentTip);
        }

        $this->updateHead($currentTip);

        return new RebaseResult(replayedCommits: count($localCommits), newHeadId: $currentTip);
    }

    private function cherryPick(ObjectId $commitId, ObjectId $ontoId): ObjectId
    {
        $commit = $this->repository->objects->read($commitId);
        if (! $commit instanceof Commit) {
            throw new PureGitException('Cannot cherry-pick non-commit object');
        }

        $parentId = $commit->parents[0] ?? null;
        if (! $parentId instanceof ObjectId) {
            throw new PureGitException('Cannot cherry-pick commit without parent');
        }

        $mergedFiles = $this->mergeTreeFiles($parentId, $ontoId, $commitId);
        $treeId = $this->buildTreeFromFiles($mergedFiles);

        $newCommit = new Commit($treeId, [$ontoId], $commit->author, $commit->committer, $commit->message);
        $this->repository->objects->write($newCommit);

        return $newCommit->getId();
    }

    /**
     * @return array<string, string>
     */
    private function mergeTreeFiles(ObjectId $baseCommitId, ObjectId $oursCommitId, ObjectId $theirsCommitId): array
    {
        $baseFiles = $this->collectFilesFromCommit($baseCommitId);
        $oursFiles = $this->collectFilesFromCommit($oursCommitId);
        $theirsFiles = $this->collectFilesFromCommit($theirsCommitId);

        $allPaths = array_unique(array_merge(array_keys($baseFiles), array_keys($oursFiles), array_keys($theirsFiles)));
        sort($allPaths);

        $mergeStrategy = new ThreeWayMerge();
        $merged = [];

        foreach ($allPaths as $path) {
            $merged[$path] = $this->mergeFile(
                $baseFiles[$path] ?? '',
                $oursFiles[$path] ?? '',
                $theirsFiles[$path] ?? '',
                $mergeStrategy,
            );
        }

        return array_filter($merged, static fn (string $content): bool => $content !== '');
    }

    private function mergeFile(string $base, string $ours, string $theirs, ThreeWayMerge $strategy): string
    {
        if ($ours === $theirs) {
            return $ours;
        }

        if ($base === $ours) {
            return $theirs;
        }

        if ($base === $theirs) {
            return $ours;
        }

        $result = $strategy->merge(
            $this->splitLines($base),
            $this->splitLines($ours),
            $this->splitLines($theirs),
        );

        if ($result->isConflicted) {
            throw new PureGitException('Rebase conflict — aborting');
        }

        return $result->mergedContent;
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

        $files = [];
        $this->collectFilesRecursive($commit->treeId, '', $files);

        return $files;
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
            $this->collectEntryContent($entry->objectId, $entry->isTree(), $path, $files);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function collectEntryContent(ObjectId $objectId, bool $isTree, string $path, array &$files): void
    {
        if ($isTree) {
            $this->collectFilesRecursive($objectId, $path, $files);
            return;
        }

        $blob = $this->repository->objects->read($objectId);
        if ($blob instanceof Blob) {
            $files[$path] = $blob->content;
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function buildTreeFromFiles(array $files): ObjectId
    {
        $commitHandler = new CommitHandler($this->repository);
        $index = new \Lukasojd\PureGit\Domain\Index\Index();

        foreach ($files as $path => $content) {
            $blob = new Blob($content);
            $this->repository->objects->write($blob);
            $entry = \Lukasojd\PureGit\Domain\Index\IndexEntry::create(
                $path,
                $blob->getId(),
                \Lukasojd\PureGit\Domain\Object\FileMode::Regular,
                strlen($content),
            );
            $index->addEntry($entry);
        }

        return $commitHandler->buildTree($index);
    }

    /**
     * @return list<ObjectId>
     */
    private function collectLocalCommits(ObjectId $headId, ?ObjectId $baseId): array
    {
        $commits = [];
        $currentId = $headId;

        while (true) {
            if ($baseId instanceof ObjectId && $currentId->equals($baseId)) {
                break;
            }

            $commits[] = $currentId;

            $commit = $this->repository->objects->read($currentId);
            if (! $commit instanceof Commit || $commit->parents === []) {
                break;
            }

            $currentId = $commit->parents[0];
        }

        return array_reverse($commits);
    }

    private function updateHead(ObjectId $commitId): void
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
