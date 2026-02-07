<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI;

use Lukasojd\PureGit\CLI\Command\CliCommand;

final class Application
{
    private const string VERSION = '1.0.0';

    /**
     * @var array<string, class-string<CliCommand>>
     */
    private const array COMMAND_MAP = [
        'init' => Command\InitCommand::class,
        'add' => Command\AddCommand::class,
        'commit' => Command\CommitCommand::class,
        'status' => Command\StatusCommand::class,
        'log' => Command\LogCommand::class,
        'diff' => Command\DiffCommand::class,
        'branch' => Command\BranchCommand::class,
        'tag' => Command\TagCommand::class,
        'checkout' => Command\CheckoutCommand::class,
        'merge' => Command\MergeCommand::class,
        'reset' => Command\ResetCommand::class,
        'show' => Command\ShowCommand::class,
        'rm' => Command\RmCommand::class,
        'mv' => Command\MvCommand::class,
        'commit-graph' => Command\CommitGraphCommand::class,
        'clone' => Command\CloneCommand::class,
        'fetch' => Command\FetchCommand::class,
        'pull' => Command\PullCommand::class,
        'push' => Command\PushCommand::class,
        'config' => Command\ConfigCommand::class,
    ];

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        array_shift($argv);

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

        if (! isset(self::COMMAND_MAP[$commandName])) {
            fwrite(STDERR, sprintf("puregit: '%s' is not a puregit command. See 'puregit --help'.\n", $commandName));

            return 1;
        }

        $command = new (self::COMMAND_MAP[$commandName])();

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

    private function printUsage(): void
    {
        fwrite(STDOUT, sprintf("puregit version %s — Pure PHP Git implementation\n\n", self::VERSION));
        fwrite(STDOUT, "Usage: puregit <command> [<args>]\n\n");
        fwrite(STDOUT, "Available commands:\n");

        foreach (self::COMMAND_MAP as $name => $class) {
            $command = new $class();
            fwrite(STDOUT, sprintf("  %-12s %s\n", $name, $command->description()));
        }

        fwrite(STDOUT, "\nSee 'puregit <command> --help' for more information on a specific command.\n");
    }

    private function printVersion(): void
    {
        fwrite(STDOUT, sprintf("puregit version %s\n", self::VERSION));
    }
}
