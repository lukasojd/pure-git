<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphReader;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphWriter;

final readonly class CommitGraphHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function write(): CommitGraphResult
    {
        $graphPath = $this->graphPath();
        $start = hrtime(true);

        $writer = new CommitGraphWriter();
        $count = $writer->write($this->repository->objects, $this->repository->refs, $graphPath);

        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $size = file_exists($graphPath) ? (int) filesize($graphPath) : 0;

        return new CommitGraphResult(
            commitCount: $count,
            elapsedMs: $elapsed,
            fileSizeBytes: $size,
        );
    }

    public function verify(bool $full = false): VerifyResult
    {
        $graphPath = $this->graphPath();

        if (! file_exists($graphPath)) {
            return new VerifyResult(valid: false, message: 'Commit-graph file not found');
        }

        try {
            $reader = new CommitGraphReader($graphPath);
            $count = $reader->getCommitCount();
        } catch (\Throwable $e) {
            return new VerifyResult(valid: false, message: $e->getMessage());
        }

        if (! $full) {
            return new VerifyResult(valid: true, message: sprintf('Commit-graph is valid (%d commits)', $count));
        }

        return $this->fullVerify($count);
    }

    private function fullVerify(int $graphCount): VerifyResult
    {
        // Re-run the writer's BFS to get the authoritative count
        $writer = new CommitGraphWriter();
        $bfsCount = $writer->countReachable($this->repository->objects, $this->repository->refs);

        if ($bfsCount !== $graphCount) {
            return new VerifyResult(
                valid: false,
                message: sprintf('Commit count mismatch: graph has %d, BFS found %d', $graphCount, $bfsCount),
            );
        }

        return new VerifyResult(valid: true, message: sprintf('Commit-graph is valid (%d commits, full verify)', $graphCount));
    }

    private function graphPath(): string
    {
        $infoDir = $this->repository->gitDir . '/objects/info';
        if (! is_dir($infoDir)) {
            mkdir($infoDir, 0o777, true);
        }

        return $infoDir . '/commit-graph';
    }
}
