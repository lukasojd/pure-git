<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\CommitHandler;
use Lukasojd\PureGit\Application\Service\Repository;

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
        $message = null;
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            if ($args[$i] === '-m' && isset($args[$i + 1])) {
                $message = $args[$i + 1];
                $i++;
            }
        }

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
        $handler = new CommitHandler($repo);
        $commitId = $handler->handle($message);

        fwrite(STDOUT, sprintf("[commit %s] %s\n", $commitId->short(), $message));

        return 0;
    }
}
