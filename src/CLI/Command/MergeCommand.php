<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Handler\MergeHandler;
use Lukasojd\PureGit\Application\Handler\MergeResult;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\CLI\Formatter\DiffStatFormatter;
use Lukasojd\PureGit\Domain\Exception\MergeConflictException;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;

final class MergeCommand implements CliCommand
{
    public function name(): string
    {
        return 'merge';
    }

    public function description(): string
    {
        return 'Join two or more development histories together';
    }

    public function usage(): string
    {
        return 'merge <branch>';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        if ($args === []) {
            fwrite(STDERR, "error: merge requires a branch name\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new MergeHandler($repo);

        return $this->performMerge($handler, $repo, $args[0]);
    }

    private function performMerge(MergeHandler $handler, Repository $repo, string $branchName): int
    {
        try {
            $result = $handler->handle($branchName);

            if ($result->fastForward) {
                $this->printFastForward($result, $repo);
            } else {
                fwrite(STDOUT, sprintf("Merge made with commit %s\n", $result->commitId->short()));
            }

            return 0;
        } catch (MergeConflictException $e) {
            $this->printConflicts($e);

            return 1;
        }
    }

    private function printFastForward(MergeResult $result, Repository $repo): void
    {
        fwrite(STDOUT, sprintf("Updating %s..%s\n", $result->oldId->short(), $result->commitId->short()));
        fwrite(STDOUT, "Fast-forward\n");

        $diffHandler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $diffHandler->diffCommits($result->oldId, $result->commitId);
        $stat = DiffStatFormatter::format($diffs);

        if ($stat !== '') {
            fwrite(STDOUT, $stat . "\n");
        }
    }

    private function printConflicts(MergeConflictException $e): void
    {
        fwrite(STDERR, "Automatic merge failed; fix conflicts and then commit the result.\n");
        foreach ($e->conflictedPaths as $path) {
            fwrite(STDERR, sprintf("  CONFLICT: %s\n", $path));
        }
    }
}
