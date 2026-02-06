<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\CLI\Formatter\DiffStatFormatter;
use Lukasojd\PureGit\Domain\Ref\RefName;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;

final class CommitCommand implements CliCommand
{
    public function name(): string
    {
        return 'commit';
    }

    public function description(): string
    {
        return 'Record changes to the repository';
    }

    public function usage(): string
    {
        return 'commit -m <message>';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $message = $this->parseMessage($args);
        if ($message === null) {
            fwrite(STDERR, "error: switch 'm' requires a value\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);

        return $this->doCommit($repo, $message);
    }

    private function doCommit(Repository $repo, string $message): int
    {
        $isRootCommit = ! $this->hasHead($repo);
        $diffHandler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $isRootCommit ? [] : $diffHandler->diffIndexVsHead();

        $handler = new CommitHandler($repo);
        $commitId = $handler->handle($message);

        $branchName = $this->getCurrentBranchName($repo);
        $rootLabel = $isRootCommit ? ' (root-commit)' : '';
        fwrite(STDOUT, sprintf("[%s%s %s] %s\n", $branchName, $rootLabel, $commitId->short(), $message));

        $stat = DiffStatFormatter::format($diffs);
        if ($stat !== '') {
            fwrite(STDOUT, $stat . "\n");
        }

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function parseMessage(array $args): ?string
    {
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            if ($args[$i] === '-m' && isset($args[$i + 1])) {
                return $args[$i + 1];
            }
        }

        return null;
    }

    private function getCurrentBranchName(Repository $repo): string
    {
        $branchHandler = new BranchHandler($repo);
        $currentBranch = $branchHandler->getCurrentBranch();

        if ($currentBranch instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            return $currentBranch->shortName();
        }

        return 'HEAD';
    }

    private function hasHead(Repository $repo): bool
    {
        try {
            $repo->refs->resolve(RefName::head());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
