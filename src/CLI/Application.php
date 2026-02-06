<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI;

use Lukasojd\PureGit\CLI\Command\AddCommand;
use Lukasojd\PureGit\CLI\Command\BranchCommand;
use Lukasojd\PureGit\CLI\Command\CheckoutCommand;
use Lukasojd\PureGit\CLI\Command\CliCommand;
use Lukasojd\PureGit\CLI\Command\CloneCommand;
use Lukasojd\PureGit\CLI\Command\CommitCommand;
use Lukasojd\PureGit\CLI\Command\CommitGraphCommand;
use Lukasojd\PureGit\CLI\Command\DiffCommand;
use Lukasojd\PureGit\CLI\Command\FetchCommand;
use Lukasojd\PureGit\CLI\Command\InitCommand;
use Lukasojd\PureGit\CLI\Command\LogCommand;
use Lukasojd\PureGit\CLI\Command\MergeCommand;
use Lukasojd\PureGit\CLI\Command\MvCommand;
use Lukasojd\PureGit\CLI\Command\PullCommand;
use Lukasojd\PureGit\CLI\Command\PushCommand;
use Lukasojd\PureGit\CLI\Command\ResetCommand;
use Lukasojd\PureGit\CLI\Command\RmCommand;
use Lukasojd\PureGit\CLI\Command\ShowCommand;
use Lukasojd\PureGit\CLI\Command\StatusCommand;
use Lukasojd\PureGit\CLI\Command\TagCommand;

final class Application
{
    private const string VERSION = '0.1.0';

    /**
     * @var array<string, CliCommand>
     */
    private array $commands = [];

    public function __construct()
    {
        $this->registerCommand(new InitCommand());
        $this->registerCommand(new AddCommand());
        $this->registerCommand(new CommitCommand());
        $this->registerCommand(new StatusCommand());
        $this->registerCommand(new LogCommand());
        $this->registerCommand(new DiffCommand());
        $this->registerCommand(new BranchCommand());
        $this->registerCommand(new TagCommand());
        $this->registerCommand(new CheckoutCommand());
        $this->registerCommand(new MergeCommand());
        $this->registerCommand(new ResetCommand());
        $this->registerCommand(new ShowCommand());
        $this->registerCommand(new RmCommand());
        $this->registerCommand(new MvCommand());
        $this->registerCommand(new CommitGraphCommand());
        $this->registerCommand(new CloneCommand());
        $this->registerCommand(new FetchCommand());
        $this->registerCommand(new PullCommand());
        $this->registerCommand(new PushCommand());
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        array_shift($argv) ?? 'puregit';

        if ($argv === []) {
            $this->printUsage();

            return 0;
        }

        $commandName = array_shift($argv);

        if ($commandName === '--help' || $commandName === '-h') {
            $this->printUsage();

            return 0;
        }

        if ($commandName === '--version' || $commandName === '-v') {
            $this->printVersion();

            return 0;
        }

        if ($commandName === null || ! isset($this->commands[$commandName])) {
            fwrite(STDERR, sprintf("puregit: '%s' is not a puregit command. See 'puregit --help'.\n", $commandName ?? ''));

            return 1;
        }

        $command = $this->commands[$commandName];

        if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            fwrite(STDOUT, sprintf("Usage: puregit %s\n\n%s\n", $command->usage(), $command->description()));

            return 0;
        }

        try {
            return $command->execute($argv);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("fatal: %s\n", $e->getMessage()));

            return 128;
        }
    }

    private function registerCommand(CliCommand $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    private function printUsage(): void
    {
        fwrite(STDOUT, sprintf("puregit version %s — Pure PHP Git implementation\n\n", self::VERSION));
        fwrite(STDOUT, "Usage: puregit <command> [<args>]\n\n");
        fwrite(STDOUT, "Available commands:\n");

        foreach ($this->commands as $name => $command) {
            fwrite(STDOUT, sprintf("  %-12s %s\n", $name, $command->description()));
        }

        fwrite(STDOUT, "\nSee 'puregit <command> --help' for more information on a specific command.\n");
    }

    private function printVersion(): void
    {
        fwrite(STDOUT, sprintf("puregit version %s\n", self::VERSION));
    }
}
