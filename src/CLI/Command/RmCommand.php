<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\RmHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class RmCommand implements CliCommand
{
    public function name(): string
    {
        return 'rm';
    }

    public function description(): string
    {
        return 'Remove files from the working tree and the index';
    }

    public function usage(): string
    {
        return 'rm [--cached] <file>...';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $cached = false;
        $paths = [];

        foreach ($args as $arg) {
            if ($arg === '--cached') {
                $cached = true;
            } else {
                $paths[] = $arg;
            }
        }

        if ($paths === []) {
            fwrite(STDERR, "error: rm requires file arguments\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new RmHandler($repo);
        $handler->handle($paths, $cached);

        foreach ($paths as $path) {
            fwrite(STDOUT, sprintf("rm '%s'\n", $path));
        }

        return 0;
    }
}
