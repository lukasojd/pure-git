<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\PushHandler;
use Lukasojd\PureGit\Application\Handler\PushResult;
use Lukasojd\PureGit\Application\Service\Repository;

final class PushCommand implements CliCommand
{
    public function name(): string
    {
        return 'push';
    }

    public function description(): string
    {
        return 'Update remote refs along with associated objects';
    }

    public function usage(): string
    {
        return 'push [-u|--set-upstream] [<remote>] [<refspec>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        [$remoteName, $refspec, $setUpstream] = $this->parseArgs($args);

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "fatal: unable to determine current directory\n");

            return 128;
        }

        $repository = Repository::discover($cwd);
        $handler = new PushHandler($repository);
        $result = $handler->push($remoteName, $refspec);

        $this->printResult($result);

        if ($setUpstream && ! $result->upToDate) {
            $localRef = $refspec ?? $this->getCurrentBranch($repository);
            if ($localRef !== null) {
                $handler->setUpstreamTracking($remoteName, $localRef);
                $branchName = $this->shortRefName($localRef);
                fwrite(STDERR, sprintf("branch '%s' set up to track '%s/%s'.\n", $branchName, $remoteName, $branchName));
            }
        }

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{string, string|null, bool}
     */
    private function parseArgs(array $args): array
    {
        $remoteName = 'origin';
        $refspec = null;
        $setUpstream = false;

        $positional = [];
        foreach ($args as $arg) {
            if ($arg === '-u' || $arg === '--set-upstream') {
                $setUpstream = true;
            } elseif (! str_starts_with($arg, '-')) {
                $positional[] = $arg;
            }
        }

        if (isset($positional[0])) {
            $remoteName = $positional[0];
        }
        if (isset($positional[1])) {
            $refspec = $positional[1];
        }

        return [$remoteName, $refspec, $setUpstream];
    }

    private function printResult(PushResult $result): void
    {
        fwrite(STDERR, sprintf("To %s\n", $result->remoteUrl));

        if ($result->upToDate) {
            fwrite(STDOUT, "Everything up-to-date\n");

            return;
        }

        foreach ($result->refUpdates as $update) {
            $this->printRefUpdate($update);
        }
    }

    private function printRefUpdate(\Lukasojd\PureGit\Application\Handler\PushRefUpdate $update): void
    {
        $oldShort = $update->oldHash !== null ? substr($update->oldHash, 0, 7) : '*';
        $newShort = substr($update->newHash, 0, 7);

        if ($update->oldHash === null) {
            fwrite(STDERR, sprintf(" * [new branch]      %s -> %s\n", $newShort, $this->shortRefName($update->refName)));
        } else {
            fwrite(STDERR, sprintf("   %s..%s  %s -> %s\n", $oldShort, $newShort, $this->shortRefName($update->refName), $this->shortRefName($update->refName)));
        }
    }

    private function shortRefName(string $refName): string
    {
        if (str_starts_with($refName, 'refs/heads/')) {
            return substr($refName, strlen('refs/heads/'));
        }
        if (str_starts_with($refName, 'refs/tags/')) {
            return substr($refName, strlen('refs/tags/'));
        }

        return $refName;
    }

    private function getCurrentBranch(Repository $repository): ?string
    {
        $head = \Lukasojd\PureGit\Domain\Ref\RefName::head();
        $symbolicRef = $repository->refs->getSymbolicRef($head);

        if ($symbolicRef instanceof \Lukasojd\PureGit\Domain\Ref\RefName) {
            return $symbolicRef->value;
        }

        return null;
    }
}
