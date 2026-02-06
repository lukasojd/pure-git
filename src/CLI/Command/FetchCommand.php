<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\FetchHandler;
use Lukasojd\PureGit\Application\Handler\FetchResult;
use Lukasojd\PureGit\Application\Handler\RefUpdate;
use Lukasojd\PureGit\Application\Service\Repository;

final class FetchCommand implements CliCommand
{
    public function name(): string
    {
        return 'fetch';
    }

    public function description(): string
    {
        return 'Download objects and refs from a remote repository';
    }

    public function usage(): string
    {
        return 'fetch [--all] [<remote>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        [$all, $remoteName] = $this->parseArgs($args);

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: unable to determine current directory\n");

            return 128;
        }

        $repository = Repository::discover($cwd);
        $handler = new FetchHandler($repository);

        $results = $all ? $handler->fetchAll() : [$handler->fetch($remoteName)];
        foreach ($results as $result) {
            $this->printResult($result);
        }

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{bool, string}
     */
    private function parseArgs(array $args): array
    {
        $all = false;
        $remoteName = 'origin';

        foreach ($args as $arg) {
            if ($arg === '--all') {
                $all = true;
            } else {
                $remoteName = $arg;
            }
        }

        return [$all, $remoteName];
    }

    private function printResult(FetchResult $result): void
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
