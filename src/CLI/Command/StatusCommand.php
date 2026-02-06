<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\StatusHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\FileStatus;

final class StatusCommand implements CliCommand
{
    public function name(): string
    {
        return 'status';
    }

    public function description(): string
    {
        return 'Show the working tree status';
    }

    public function usage(): string
    {
        return 'status';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new StatusHandler($repo);
        $result = $handler->handle();

        $this->printBranchHeader($repo);
        $this->printStagedChanges($result['staged']);
        $this->printUnstagedChanges($result['unstaged']);
        $this->printUntrackedFiles($result['untracked']);

        if ($result['staged'] === [] && $result['unstaged'] === [] && $result['untracked'] === []) {
            fwrite(STDOUT, "nothing to commit, working tree clean\n");
        }

        return 0;
    }

    private function printBranchHeader(Repository $repo): void
    {
        $branchHandler = new BranchHandler($repo);
        $currentBranch = $branchHandler->getCurrentBranch();

        if ($currentBranch instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            fwrite(STDOUT, sprintf("On branch %s\n", $currentBranch->shortName()));
        } else {
            fwrite(STDOUT, "HEAD detached\n");
        }
    }

    /**
     * @param array<string, FileStatus> $staged
     */
    private function printStagedChanges(array $staged): void
    {
        if ($staged === []) {
            return;
        }

        fwrite(STDOUT, "\nChanges to be committed:\n");
        foreach ($staged as $path => $status) {
            $label = match ($status) {
                FileStatus::Added => 'new file',
                FileStatus::Modified => 'modified',
                FileStatus::Deleted => 'deleted',
                default => $status->value,
            };
            fwrite(STDOUT, sprintf("  %s: %s\n", $label, $path));
        }
    }

    /**
     * @param array<string, FileStatus> $unstaged
     */
    private function printUnstagedChanges(array $unstaged): void
    {
        if ($unstaged === []) {
            return;
        }

        fwrite(STDOUT, "\nChanges not staged for commit:\n");
        foreach ($unstaged as $path => $status) {
            $label = match ($status) {
                FileStatus::Modified => 'modified',
                FileStatus::Deleted => 'deleted',
                default => $status->value,
            };
            fwrite(STDOUT, sprintf("  %s: %s\n", $label, $path));
        }
    }

    /**
     * @param list<string> $untracked
     */
    private function printUntrackedFiles(array $untracked): void
    {
        if ($untracked === []) {
            return;
        }

        fwrite(STDOUT, "\nUntracked files:\n");
        foreach ($untracked as $path) {
            fwrite(STDOUT, sprintf("  %s\n", $path));
        }
    }
}
