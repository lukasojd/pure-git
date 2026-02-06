<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\BranchHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutResult;
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
        return 'checkout [-b <new-branch>] <branch|commit>';
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

        if ($args[0] === '-b') {
            if (! isset($args[1])) {
                fwrite(STDERR, "error: switch 'b' requires a value\n");

                return 1;
            }

            $startPoint = $args[2] ?? null;
            $result = $handler->checkoutNewBranch($args[1], $startPoint);
            $this->printResult($result, $args[1], $repo);

            return 0;
        }

        $result = $handler->checkout($args[0]);

        $this->printResult($result, $args[0], $repo);

        return 0;
    }

    private function printResult(CheckoutResult $result, string $target, Repository $repo): void
    {
        match ($result) {
            CheckoutResult::AlreadyOnBranch => fwrite(STDOUT, sprintf("Already on '%s'\n", $target)),
            CheckoutResult::SwitchedToBranch => fwrite(STDOUT, sprintf("Switched to branch '%s'\n", $target)),
            CheckoutResult::DetachedHead => fwrite(STDOUT, sprintf("HEAD is now at %s\n", substr($target, 0, 7))),
            CheckoutResult::CreatedAndSwitched => fwrite(STDOUT, sprintf("Switched to a new branch '%s'\n", $target)),
        };

        $this->printTrackingInfo($repo);
    }

    private function printTrackingInfo(Repository $repo): void
    {
        $branchHandler = new BranchHandler($repo);
        $tracking = $branchHandler->getTrackingInfo();

        if ($tracking instanceof \Lukasojd\PureGit\Application\Handler\TrackingInfo) {
            fwrite(STDOUT, $tracking->formatMessage() . "\n");
        }
    }
}
