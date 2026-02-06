<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class CheckoutCommand implements CliCommand
{
    public function name(): string
    {
        return 'checkout';
    }

    public function description(): string
    {
        return 'Switch branches or restore working tree files';
    }

    public function usage(): string
    {
        return 'checkout <branch|commit>';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        if ($args === []) {
            fwrite(STDERR, "error: switch 'checkout' requires a target\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new CheckoutHandler($repo);
        $handler->checkout($args[0]);

        fwrite(STDOUT, sprintf("Switched to '%s'\n", $args[0]));

        return 0;
    }
}
