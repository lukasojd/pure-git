<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\AddHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Support\PathUtils;

final class AddCommand implements CliCommand
{
    public function name(): string
    {
        return 'add';
    }

    public function description(): string
    {
        return 'Add file contents to the index';
    }

    public function usage(): string
    {
        return 'add <pathspec>...';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        if ($args === []) {
            fwrite(STDERR, "Nothing specified, nothing added.\n");

            return 0;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new AddHandler($repo);

        $paths = [];
        foreach ($args as $arg) {
            if ($arg === '.') {
                // Add all files
                $files = $repo->filesystem->listFilesRecursive($repo->workDir);
                foreach ($files as $file) {
                    if (! str_starts_with($file, '.git/') && $file !== '.git') {
                        $paths[] = $file;
                    }
                }
            } else {
                $paths[] = PathUtils::relativeTo($arg, $repo->workDir);
            }
        }

        $handler->handle($paths);

        return 0;
    }
}
