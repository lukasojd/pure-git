<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class BranchCommand implements CliCommand
{
    public function name(): string
    {
        return 'branch';
    }

    public function description(): string
    {
        return 'List, create, or delete branches';
    }

    public function usage(): string
    {
        return 'branch [<name>] [-d <name>]';
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
        $handler = new BranchHandler($repo);

        if ($this->isDeleteRequest($args)) {
            return $this->deleteBranch($handler, $args[1]);
        }

        if ($this->isCreateRequest($args)) {
            return $this->createBranch($handler, $args[0]);
        }

        return $this->listBranches($handler);
    }

    /**
     * @param list<string> $args
     */
    private function isDeleteRequest(array $args): bool
    {
        return isset($args[0]) && $args[0] === '-d' && isset($args[1]);
    }

    /**
     * @param list<string> $args
     */
    private function isCreateRequest(array $args): bool
    {
        return isset($args[0]) && $args[0] !== '' && $args[0][0] !== '-';
    }

    private function deleteBranch(BranchHandler $handler, string $name): int
    {
        $handler->delete($name);
        fwrite(STDOUT, sprintf("Deleted branch %s\n", $name));

        return 0;
    }

    private function createBranch(BranchHandler $handler, string $name): int
    {
        $handler->create($name);
        fwrite(STDOUT, sprintf("Created branch %s\n", $name));

        return 0;
    }

    private function listBranches(BranchHandler $handler): int
    {
        $branches = $handler->list();
        $currentBranch = $handler->getCurrentBranch();

        foreach (array_keys($branches) as $refName) {
            $short = str_replace('refs/heads/', '', $refName);
            $isCurrent = $currentBranch instanceof \Lukasojd\PureGit\Domain\Ref\RefName && $currentBranch->shortName() === $short;
            $prefix = $isCurrent ? '* ' : '  ';
            fwrite(STDOUT, sprintf("%s%s\n", $prefix, $short));
        }

        return 0;
    }
}
