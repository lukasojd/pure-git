<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\ResetHandler;
use Lukasojd\PureGit\Application\Handler\ResetMode;
use Lukasojd\PureGit\Application\Service\Repository;

final class ResetCommand implements CliCommand
{
    public function name(): string
    {
        return 'reset';
    }

    public function description(): string
    {
        return 'Reset current HEAD to the specified state';
    }

    public function usage(): string
    {
        return 'reset [--soft|--mixed|--hard] <commit>';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $mode = ResetMode::Mixed;
        $target = 'HEAD';

        foreach ($args as $arg) {
            if ($arg === '--soft') {
                $mode = ResetMode::Soft;
            } elseif ($arg === '--mixed') {
                $mode = ResetMode::Mixed;
            } elseif ($arg === '--hard') {
                $mode = ResetMode::Hard;
            } elseif ($arg[0] !== '-') {
                $target = $arg;
            }
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new ResetHandler($repo);
        $handler->handle($target, $mode);

        fwrite(STDOUT, sprintf("HEAD is now at %s\n", $target));

        return 0;
    }
}
