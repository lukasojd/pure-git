<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\LogHandler;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Object\Commit;

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
        return 'log [-n <number>] [--oneline] [--all]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        [$maxCount, $oneline, $all] = $this->parseArgs($args);

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new LogHandler($repo);
        $commits = $handler->handle($maxCount, all: $all);

        foreach ($commits as $i => $commit) {
            $oneline ? $this->printOneline($commit) : $this->printFull($commit, $i);
        }

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{int, bool, bool}
     */
    private function parseArgs(array $args): array
    {
        $maxCount = 20;
        $oneline = false;
        $all = false;
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            if ($args[$i] === '-n' && isset($args[$i + 1])) {
                $maxCount = (int) $args[$i + 1];
                $i++;
            } elseif ($args[$i] === '--oneline') {
                $oneline = true;
            } elseif ($args[$i] === '--all') {
                $all = true;
            }
        }

        return [$maxCount, $oneline, $all];
    }

    private function printOneline(Commit $commit): void
    {
        $firstLine = strstr($commit->message, "\n", true);
        fwrite(STDOUT, sprintf("%s %s\n", $commit->getId()->short(7), $firstLine !== false ? $firstLine : rtrim($commit->message)));
    }

    private function printFull(Commit $commit, int $index): void
    {
        if ($index > 0) {
            fwrite(STDOUT, "\n");
        }
        fwrite(STDOUT, sprintf("commit %s\n", $commit->getId()->hash));
        fwrite(STDOUT, sprintf("Author: %s <%s>\n", $commit->author->name, $commit->author->email));
        fwrite(STDOUT, sprintf("Date:   %s\n", $commit->author->timestamp->format('D M j H:i:s Y O')));
        fwrite(STDOUT, sprintf("\n    %s\n", rtrim($commit->message)));
    }
}
