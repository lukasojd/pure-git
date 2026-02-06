<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Service\Repository;

final class InitCommand implements CliCommand
{
    public function name(): string
    {
        return 'init';
    }

    public function description(): string
    {
        return 'Create an empty PureGit repository';
    }

    public function usage(): string
    {
        return 'init [<directory>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $path = $args[0] ?? getcwd();
        if ($path === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        if (! is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        $path = realpath($path);
        if ($path === false) {
            fwrite(STDERR, "fatal: Cannot resolve path\n");

            return 128;
        }

        Repository::init($path);
        fwrite(STDOUT, sprintf("Initialized empty PureGit repository in %s/.git/\n", $path));

        return 0;
    }
}
