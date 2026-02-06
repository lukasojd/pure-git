<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\CommitGraphHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class CommitGraphCommand implements CliCommand
{
    public function name(): string
    {
        return 'commit-graph';
    }

    public function description(): string
    {
        return 'Write or verify the commit-graph file';
    }

    public function usage(): string
    {
        return 'commit-graph <write|verify> [--full]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $subcommand = $args[0] ?? null;

        if ($subcommand === null || ! in_array($subcommand, ['write', 'verify'], true)) {
            fwrite(STDERR, "Usage: puregit commit-graph <write|verify> [--full]\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: Cannot determine current directory\n");

            return 128;
        }

        $repo = Repository::discover($cwd);
        $handler = new CommitGraphHandler($repo);

        if ($subcommand === 'write') {
            return $this->handleWrite($handler);
        }

        return $this->handleVerify($handler, in_array('--full', $args, true));
    }

    private function handleWrite(CommitGraphHandler $handler): int
    {
        $result = $handler->write();

        fwrite(STDOUT, sprintf(
            "Building commit-graph for %d commits... done in %.0f ms (%d KB)\n",
            $result->commitCount,
            $result->elapsedMs,
            (int) ($result->fileSizeBytes / 1024),
        ));

        return 0;
    }

    private function handleVerify(CommitGraphHandler $handler, bool $full): int
    {
        $result = $handler->verify($full);

        if ($result->valid) {
            fwrite(STDOUT, $result->message . "\n");

            return 0;
        }

        fwrite(STDERR, sprintf("error: %s\n", $result->message));

        return 1;
    }
}
