<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\LogHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class LogCommand implements CliCommand
{
    public function name(): string
    {
        return 'log';
    }

    public function description(): string
    {
        return 'Show commit logs';
    }

    public function usage(): string
    {
        return 'log [-n <number>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $maxCount = 20;
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            if ($args[$i] === '-n' && isset($args[$i + 1])) {
                $maxCount = (int) $args[$i + 1];
                $i++;
            }
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new LogHandler($repo);
        $commits = $handler->handle($maxCount);

        $isFirst = true;
        foreach ($commits as $commit) {
            if (! $isFirst) {
                fwrite(STDOUT, "\n");
            }
            fwrite(STDOUT, sprintf("commit %s\n", $commit->getId()->hash));
            fwrite(STDOUT, sprintf("Author: %s <%s>\n", $commit->author->name, $commit->author->email));
            fwrite(STDOUT, sprintf("Date:   %s\n", $commit->author->timestamp->format('D M j H:i:s Y O')));
            fwrite(STDOUT, sprintf("\n    %s\n", $commit->message));
            $isFirst = false;
        }

        return 0;
    }
}
