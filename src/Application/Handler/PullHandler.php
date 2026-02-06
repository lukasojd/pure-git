<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;

final readonly class PullHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function pull(string $remoteName = 'origin', bool $rebase = false): PullResult
    {
        [$branch, $trackingRef] = $this->resolveUpstream($remoteName);

        $fetchHandler = new FetchHandler($this->repository);
        $fetchResult = $fetchHandler->fetch($remoteName);

        if ($fetchResult->upToDate) {
            return new PullResult(
                fetchResult: $fetchResult,
                mergeCommitId: null,
                upToDate: true,
                fastForward: false,
                rebase: false,
            );
        }

        $theirsId = $this->repository->refs->resolve(RefName::fromString($trackingRef));

        if ($rebase) {
            return $this->pullRebase($fetchResult, $theirsId);
        }

        return $this->pullMerge($fetchResult, $trackingRef, $theirsId);
    }

    private function pullRebase(FetchResult $fetchResult, ObjectId $theirsId): PullResult
    {
        $rebaseHandler = new RebaseHandler($this->repository);
        $result = $rebaseHandler->rebase($theirsId);

        return new PullResult(
            fetchResult: $fetchResult,
            mergeCommitId: null,
            upToDate: false,
            fastForward: $result->replayedCommits === 0,
            rebase: true,
        );
    }

    private function pullMerge(FetchResult $fetchResult, string $trackingRef, ObjectId $theirsId): PullResult
    {
        $headId = $this->repository->refs->resolve(RefName::head());

        if ($headId->equals($theirsId)) {
            return new PullResult(
                fetchResult: $fetchResult,
                mergeCommitId: null,
                upToDate: true,
                fastForward: false,
                rebase: false,
            );
        }

        $resolver = new MergeBaseResolver($this->repository->objects);
        $baseId = $resolver->findMergeBase($headId, $theirsId);
        $isFastForward = $baseId instanceof ObjectId && $baseId->equals($headId);

        $mergeHandler = new MergeHandler($this->repository);
        $mergeId = $mergeHandler->mergeRef($trackingRef);

        return new PullResult(
            fetchResult: $fetchResult,
            mergeCommitId: $isFastForward ? null : $mergeId,
            upToDate: false,
            fastForward: $isFastForward,
            rebase: false,
        );
    }

    /**
     * @return array{string, string} [branchName, trackingRef]
     */
    private function resolveUpstream(string $remoteName): array
    {
        $head = RefName::head();
        $symbolicRef = $this->repository->refs->getSymbolicRef($head);
        if (! $symbolicRef instanceof RefName || ! $symbolicRef->isBranch()) {
            throw new PureGitException('You are not currently on a branch');
        }

        $branch = $symbolicRef->shortName();
        $config = new GitConfigReader($this->repository->gitDir . '/config');
        $trackingRef = $config->getUpstreamRef($branch);

        if ($trackingRef === null) {
            $trackingRef = 'refs/remotes/' . $remoteName . '/' . $branch;
        }

        return [$branch, $trackingRef];
    }
}
