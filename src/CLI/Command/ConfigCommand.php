<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\CLI\Command;

use Lukasojd\PureGit\Application\Handler\ConfigHandler;
use Lukasojd\PureGit\Application\Handler\ConfigScope;
use Lukasojd\PureGit\Application\Service\Repository;

final class ConfigCommand implements CliCommand
{
    public function name(): string
    {
        return 'config';
    }

    public function description(): string
    {
        return 'Get and set repository or global options';
    }

    public function usage(): string
    {
        return 'config [--global|--local] [--list] [--unset] <key> [<value>]';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args): int
    {
        $scope = null;
        $isList = false;
        $isUnset = false;
        $positional = [];

        foreach ($args as $arg) {
            match ($arg) {
                '--global' => $scope = ConfigScope::Global,
                '--local' => $scope = ConfigScope::Local,
                '--list', '-l' => $isList = true,
                '--unset' => $isUnset = true,
                default => $positional[] = $arg,
            };
        }

        $gitDir = $this->discoverGitDir();
        $handler = new ConfigHandler($gitDir);

        if ($isList) {
            return $this->handleList($handler, $scope);
        }

        if ($positional === []) {
            fwrite(STDERR, 'usage: puregit ' . $this->usage() . "\n");

            return 1;
        }

        $key = $positional[0];

        if ($isUnset) {
            return $this->handleUnset($handler, $key, $scope ?? ConfigScope::Local);
        }

        if (isset($positional[1])) {
            return $this->handleSet($handler, $key, $positional[1], $scope ?? ConfigScope::Local);
        }

        return $this->handleGet($handler, $key, $scope);
    }

    private function handleGet(ConfigHandler $handler, string $key, ?ConfigScope $scope): int
    {
        $value = $handler->get($key, $scope);

        if ($value === null) {
            return 1;
        }

        fwrite(STDOUT, $value . "\n");

        return 0;
    }

    private function handleSet(ConfigHandler $handler, string $key, string $value, ConfigScope $scope): int
    {
        $handler->set($key, $value, $scope);

        return 0;
    }

    private function handleUnset(ConfigHandler $handler, string $key, ConfigScope $scope): int
    {
        $handler->unsetKey($key, $scope);

        return 0;
    }

    private function handleList(ConfigHandler $handler, ?ConfigScope $scope): int
    {
        $values = $handler->list($scope);

        foreach ($values as $key => $value) {
            fwrite(STDOUT, $key . '=' . $value . "\n");
        }

        return 0;
    }

    private function discoverGitDir(): ?string
    {
        try {
            $cwd = getcwd();
            if ($cwd === false) {
                return null;
            }

            $repo = Repository::discover($cwd);

            return $repo->gitDir;
        } catch (\Throwable) {
            return null;
        }
    }
}
