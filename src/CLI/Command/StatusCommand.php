<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\StatusHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Diff\FileStatus;

final class StatusCommand implements CliCommand
{
    private const string GREEN = "\033[32m";

    private const string RED = "\033[31m";

    private const string RESET = "\033[0m";

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

        $this->printFooter($result);

        return 0;
    }

    private function printBranchHeader(Repository $repo): void
    {
        $branchHandler = new BranchHandler($repo);
        $currentBranch = $branchHandler->getCurrentBranch();

        if ($currentBranch instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            fwrite(STDOUT, sprintf("On branch %s\n", $currentBranch->shortName()));
            $tracking = $branchHandler->getTrackingInfo($currentBranch);
            if ($tracking instanceof \Lukasojd\PureGit\Application\Handler\TrackingInfo) {
                fwrite(STDOUT, $tracking->formatMessage() . "\n\n");
            }
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

        fwrite(STDOUT, "Changes to be committed:\n");
        fwrite(STDOUT, "  (use \"git restore --staged <file>...\" to unstage)\n");
        foreach ($staged as $path => $status) {
            $label = match ($status) {
                FileStatus::Added => 'new file',
                FileStatus::Modified => 'modified',
                FileStatus::Deleted => 'deleted',
                default => $status->value,
            };
            fwrite(STDOUT, sprintf("\t%s%s:   %s%s\n", self::GREEN, $label, $path, self::RESET));
        }
        fwrite(STDOUT, "\n");
    }

    /**
     * @param array<string, FileStatus> $unstaged
     */
    private function printUnstagedChanges(array $unstaged): void
    {
        if ($unstaged === []) {
            return;
        }

        fwrite(STDOUT, "Changes not staged for commit:\n");
        fwrite(STDOUT, "  (use \"git add <file>...\" to update what will be committed)\n");
        fwrite(STDOUT, "  (use \"git restore <file>...\" to discard changes in working directory)\n");
        foreach ($unstaged as $path => $status) {
            $label = match ($status) {
                FileStatus::Modified => 'modified',
                FileStatus::Deleted => 'deleted',
                default => $status->value,
            };
            fwrite(STDOUT, sprintf("\t%s%s:   %s%s\n", self::RED, $label, $path, self::RESET));
        }
        fwrite(STDOUT, "\n");
    }

    /**
     * @param array{staged: array<string, FileStatus>, unstaged: array<string, FileStatus>, untracked: list<string>} $result
     */
    private function printFooter(array $result): void
    {
        if ($result['staged'] === [] && $result['unstaged'] === [] && $result['untracked'] === []) {
            fwrite(STDOUT, "nothing to commit, working tree clean\n");
        } elseif ($result['staged'] === [] && $result['unstaged'] !== []) {
            fwrite(STDOUT, "no changes added to commit (use \"git add\" and/or \"git commit -a\")\n");
        } elseif ($result['staged'] === [] && $result['unstaged'] === [] && $result['untracked'] !== []) {
            fwrite(STDOUT, "nothing added to commit but untracked files present (use \"git add\" to track)\n");
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

        fwrite(STDOUT, "Untracked files:\n");
        fwrite(STDOUT, "  (use \"git add <file>...\" to include in what will be committed)\n");
        foreach ($untracked as $path) {
            fwrite(STDOUT, sprintf("\t%s%s%s\n", self::RED, $path, self::RESET));
        }
        fwrite(STDOUT, "\n");
    }
}
