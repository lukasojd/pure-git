<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\DiffHandler;
use Lukasojd\PureGit\Application\Handler\FetchResult;
use Lukasojd\PureGit\Application\Handler\PullHandler;
use Lukasojd\PureGit\Application\Handler\PullResult;
use Lukasojd\PureGit\Application\Handler\RefUpdate;
use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\CLI\Formatter\DiffStatFormatter;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Infrastructure\Diff\MyersDiffAlgorithm;

final class PullCommand implements CliCommand
{
    public function name(): string
    {
        return 'pull';
    }

    public function description(): string
    {
        return 'Fetch from and integrate with a remote repository';
    }

    public function usage(): string
    {
        return 'pull [--rebase] [<remote>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        [$rebase, $remoteName] = $this->parseArgs($args);

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: unable to determine current directory\n");

            return 128;
        }

        $repository = Repository::discover($cwd);
        $handler = new PullHandler($repository);
        $result = $handler->pull($remoteName, $rebase);

        $this->printFetchResult($result->fetchResult);

        if ($result->upToDate) {
            fwrite(STDOUT, "Already up to date.\n");

            return 0;
        }

        $this->printMergeResult($result, $repository);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{bool, string}
     */
    private function parseArgs(array $args): array
    {
        $rebase = false;
        $remoteName = 'origin';

        foreach ($args as $arg) {
            if ($arg === '--rebase' || $arg === '-r') {
                $rebase = true;
            } elseif (! str_starts_with($arg, '-')) {
                $remoteName = $arg;
            }
        }

        return [$rebase, $remoteName];
    }

    private function printFetchResult(FetchResult $result): void
    {
        if ($result->upToDate) {
            return;
        }

        fwrite(STDERR, sprintf("From %s\n", $this->formatUrl($result->remoteUrl)));

        foreach ($result->refUpdates as $update) {
            $this->printRefUpdate($update);
        }
    }

    private function printRefUpdate(RefUpdate $update): void
    {
        $shortRemote = $this->shortRefName($update->remoteName);
        $shortLocal = $this->shortRefName($update->localName);

        if ($update->isNew()) {
            $type = $update->isTag() ? '[new tag]' : '[new branch]';
            fwrite(STDERR, sprintf(" * %-19s %s -> %s\n", $type, $shortRemote, $shortLocal));
        } else {
            $oldShort = substr((string) $update->oldHash, 0, 7);
            $newShort = substr($update->newHash, 0, 7);
            fwrite(STDERR, sprintf("   %s..%s  %s -> %s\n", $oldShort, $newShort, $shortRemote, $shortLocal));
        }
    }

    private function printMergeResult(PullResult $result, Repository $repository): void
    {
        $this->printUpdateLine($result);
        $this->printStrategyLine($result);
        $this->printDiffStat($result, $repository);
    }

    private function printUpdateLine(PullResult $result): void
    {
        if (! $result->oldHeadId instanceof ObjectId || ! $result->newHeadId instanceof ObjectId) {
            return;
        }

        fwrite(STDOUT, sprintf(
            "Updating %s..%s\n",
            $result->oldHeadId->short(),
            $result->newHeadId->short(),
        ));
    }

    private function printStrategyLine(PullResult $result): void
    {
        if ($result->rebase) {
            fwrite(STDOUT, "Successfully rebased.\n");
        } elseif ($result->fastForward) {
            fwrite(STDOUT, "Fast-forward\n");
        } elseif ($result->mergeCommitId instanceof ObjectId) {
            fwrite(STDOUT, "Merge made by the 'ort' strategy.\n");
        }
    }

    private function printDiffStat(PullResult $result, Repository $repository): void
    {
        if (! $result->oldHeadId instanceof ObjectId || ! $result->newHeadId instanceof ObjectId) {
            return;
        }

        $diffHandler = new DiffHandler($repository, new MyersDiffAlgorithm());
        $diffs = $diffHandler->diffCommits($result->oldHeadId, $result->newHeadId);

        if ($diffs === []) {
            return;
        }

        $stat = DiffStatFormatter::format($diffs);
        fwrite(STDOUT, $stat . "\n");
    }

    private function shortRefName(string $refName): string
    {
        if (str_starts_with($refName, 'refs/heads/')) {
            return substr($refName, strlen('refs/heads/'));
        }
        if (str_starts_with($refName, 'refs/remotes/')) {
            return substr($refName, strlen('refs/remotes/'));
        }
        if (str_starts_with($refName, 'refs/tags/')) {
            return substr($refName, strlen('refs/tags/'));
        }

        return $refName;
    }

    private function formatUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed !== false && isset($parsed['scheme'], $parsed['host'])) {
            $path = $parsed['path'] ?? '';

            return $parsed['host'] . $path;
        }

        return $url;
    }
}
