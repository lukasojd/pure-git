<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\TagHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class TagCommand implements CliCommand
{
    public function name(): string
    {
        return 'tag';
    }

    public function description(): string
    {
        return 'Create, list, or delete tags';
    }

    public function usage(): string
    {
        return 'tag [<name>] [-a <name> -m <message>] [-d <name>]';
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
        $handler = new TagHandler($repo);

        // Delete
        if (isset($args[0]) && $args[0] === '-d' && isset($args[1])) {
            $handler->delete($args[1]);
            fwrite(STDOUT, sprintf("Deleted tag '%s'\n", $args[1]));

            return 0;
        }

        // Annotated tag
        $annotated = false;
        $name = null;
        $message = null;
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            if ($args[$i] === '-a' && isset($args[$i + 1])) {
                $annotated = true;
                $name = $args[$i + 1];
                $i++;
            } elseif ($args[$i] === '-m' && isset($args[$i + 1])) {
                $message = $args[$i + 1];
                $i++;
            } elseif ($name === null && $args[$i][0] !== '-') {
                $name = $args[$i];
            }
        }

        if ($name !== null && $annotated && $message !== null) {
            $handler->createAnnotated($name, $message);
            fwrite(STDOUT, sprintf("Created annotated tag '%s'\n", $name));

            return 0;
        }

        if ($name !== null) {
            $handler->createLightweight($name);
            fwrite(STDOUT, sprintf("Created tag '%s'\n", $name));

            return 0;
        }

        // List
        $tags = $handler->list();
        foreach (array_keys($tags) as $refName) {
            $short = str_replace('refs/tags/', '', $refName);
            fwrite(STDOUT, sprintf("%s\n", $short));
        }

        return 0;
    }
}
