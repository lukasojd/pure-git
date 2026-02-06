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

        // Delete
        if (isset($args[0]) && $args[0] === '-d' && isset($args[1])) {
            $handler->delete($args[1]);
            fwrite(STDOUT, sprintf("Deleted branch %s\n", $args[1]));

            return 0;
        }

        // Create
        if (isset($args[0]) && $args[0] !== '' && $args[0][0] !== '-') {
            $handler->create($args[0]);
            fwrite(STDOUT, sprintf("Created branch %s\n", $args[0]));

            return 0;
        }

        // List
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
