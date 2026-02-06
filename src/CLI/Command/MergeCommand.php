<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\MergeHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\MergeConflictException;

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

        try {
            $commitId = $handler->handle($args[0]);
            fwrite(STDOUT, sprintf("Merge made with commit %s\n", $commitId->short()));

            return 0;
        } catch (MergeConflictException $e) {
            fwrite(STDERR, "Automatic merge failed; fix conflicts and then commit the result.\n");
            foreach ($e->conflictedPaths as $path) {
                fwrite(STDERR, sprintf("  CONFLICT: %s\n", $path));
            }

            return 1;
        }
    }
}
