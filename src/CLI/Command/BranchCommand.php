<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Service\Repository;

final class BranchCommand implements CliCommand
{
    public function name(): string
    {
        return 'branch';
    }

    public function description(): string
    {
        return 'List, create, or delete branches';
    }

    public function usage(): string
    {
        return 'branch [<name>] [-d <name>] [-m <old> <new>] [-a] [--set-upstream-to=<upstream>] [--unset-upstream [<name>]]';
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
        $handler = new BranchHandler($repo);

        return $this->dispatch($handler, $args);
    }

    /**
     * @param list<string> $args
     */
    private function dispatch(BranchHandler $handler, array $args): int
    {
        if ($args === []) {
            return $this->listBranches($handler, false);
        }

        return match ($args[0]) {
            '--unset-upstream' => $this->unsetUpstream($handler, $args[1] ?? null),
            '-d' => isset($args[1]) ? $this->deleteBranch($handler, $args[1]) : 1,
            '-m' => isset($args[1], $args[2]) ? $this->renameBranch($handler, $args[1], $args[2]) : 1,
            '-a' => $this->listBranches($handler, true),
            default => $this->handleDefault($handler, $args),
        };
    }

    /**
     * @param list<string> $args
     */
    private function handleDefault(BranchHandler $handler, array $args): int
    {
        $setUpstream = $this->extractSetUpstreamTo($args);
        if ($setUpstream !== null) {
            $handler->setUpstreamTo($setUpstream);
            $current = $handler->getCurrentBranch();
            $branchName = $current instanceof \Lukasojd\PureGit\Domain\Ref\RefName ? $current->shortName() : 'HEAD';
            fwrite(STDOUT, sprintf("branch '%s' set up to track '%s'.\n", $branchName, $setUpstream));

            return 0;
        }

        if (isset($args[0]) && $args[0] !== '' && $args[0][0] !== '-') {
            return $this->createBranch($handler, $args[0]);
        }

        return $this->listBranches($handler, false);
    }

    /**
     * @param list<string> $args
     */
    private function extractSetUpstreamTo(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--set-upstream-to=')) {
                return substr($arg, strlen('--set-upstream-to='));
            }
        }

        return null;
    }

    private function unsetUpstream(BranchHandler $handler, ?string $name): int
    {
        $handler->unsetUpstream($name);

        return 0;
    }

    private function deleteBranch(BranchHandler $handler, string $name): int
    {
        $handler->delete($name);
        fwrite(STDOUT, sprintf("Deleted branch %s\n", $name));

        return 0;
    }

    private function renameBranch(BranchHandler $handler, string $oldName, string $newName): int
    {
        $handler->rename($oldName, $newName);
        fwrite(STDOUT, sprintf("Branch '%s' renamed to '%s'\n", $oldName, $newName));

        return 0;
    }

    private function createBranch(BranchHandler $handler, string $name): int
    {
        $handler->create($name);
        fwrite(STDOUT, sprintf("Created branch %s\n", $name));

        return 0;
    }

    private function listBranches(BranchHandler $handler, bool $all): int
    {
        $branches = $handler->list();
        $currentBranch = $handler->getCurrentBranch();

        foreach (array_keys($branches) as $refName) {
            $short = str_replace('refs/heads/', '', $refName);
            $isCurrent = $currentBranch instanceof \Lukasojd\PureGit\Domain\Ref\RefName && $currentBranch->shortName() === $short;
            $prefix = $isCurrent ? '* ' : '  ';
            fwrite(STDOUT, sprintf("%s%s\n", $prefix, $short));
        }

        if ($all) {
            $remotes = $handler->listRemote();
            foreach (array_keys($remotes) as $refName) {
                $short = str_replace('refs/', '', $refName);
                fwrite(STDOUT, sprintf("  %s\n", $short));
            }
        }

        return 0;
    }
}
