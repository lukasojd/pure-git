<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;

final class DiffCommand implements CliCommand
{
    public function name(): string
    {
        return 'diff';
    }

    public function description(): string
    {
        return 'Show changes between commits, index, and working tree';
    }

    public function usage(): string
    {
        return 'diff [--cached]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $cached = in_array('--cached', $args, true) || in_array('--staged', $args, true);

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new DiffHandler($repo, new MyersDiffAlgorithm());

        $diffs = $cached ? $handler->diffIndexVsHead() : $handler->diffWorkingVsIndex();

        foreach ($diffs as $diff) {
            fwrite(STDOUT, sprintf("diff --puregit a/%s b/%s\n", $diff->path, $diff->path));

            foreach ($diff->hunks as $hunk) {
                fwrite(STDOUT, $hunk->header() . "\n");

                foreach ($hunk->lines as $line) {
                    fwrite(STDOUT, sprintf("%s%s\n", $line->type->value, $line->content));
                }
            }
        }

        return 0;
    }
}
