<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\MvHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class MvCommand implements CliCommand
{
    public function name(): string
    {
        return 'mv';
    }

    public function description(): string
    {
        return 'Move or rename a file';
    }

    public function usage(): string
    {
        return 'mv <source> <destination>';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        if (count($args) < 2) {
            fwrite(STDERR, "error: mv requires source and destination\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new MvHandler($repo);
        $handler->handle($args[0], $args[1]);

        fwrite(STDOUT, sprintf("Renamed '%s' -> '%s'\n", $args[0], $args[1]));

        return 0;
    }
}
