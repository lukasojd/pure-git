<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\CLI\Formatter\DiffStatFormatter;
use Lukasojd\PureGit\Domain\Object\Commit;
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
        return 'commit [-a] [--amend] [--allow-empty] -m <message>';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $message = $this->parseMessage($args);
        [$autoStage, $amend, $allowEmpty] = $this->parseFlags($args);

        if ($message === null && ! $amend) {
            fwrite(STDERR, "error: switch 'm' requires a value\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);

        if ($autoStage) {
            new AddHandler($repo)->updateTracked();
        }

        if ($amend && $message === null) {
            $message = $this->getLastCommitMessage($repo);
        }

        return $this->doCommit($repo, $message ?? '', amend: $amend, allowEmpty: $allowEmpty);
    }

    private function doCommit(Repository $repo, string $message, bool $amend = false, bool $allowEmpty = false): int
    {
        $isRootCommit = ! $this->hasHead($repo);
        $diffHandler = new DiffHandler($repo, new MyersDiffAlgorithm());
        $diffs = $isRootCommit ? [] : $diffHandler->diffIndexVsHead();

        $handler = new CommitHandler($repo);
        $commitId = $handler->handle($message, allowEmpty: $allowEmpty, amend: $amend);

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

    /**
     * @param list<string> $args
     * @return array{bool, bool, bool}
     */
    private function parseFlags(array $args): array
    {
        return [
            in_array('-a', $args, true),
            in_array('--amend', $args, true),
            in_array('--allow-empty', $args, true),
        ];
    }

    private function getLastCommitMessage(Repository $repo): string
    {
        $headId = $repo->refs->resolve(RefName::head());
        $commit = $repo->objects->read($headId);

        return $commit instanceof Commit ? $commit->message : '';
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
