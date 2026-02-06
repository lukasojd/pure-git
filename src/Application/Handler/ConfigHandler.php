<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigReader;
use Lukasojd\PureGit\Infrastructure\Config\GitConfigWriter;

final readonly class ConfigHandler
{
    public function __construct(
        private ?string $gitDir = null,
    ) {
    }

    public function get(string $key, ?ConfigScope $scope = null): ?string
    {
        [$section, $property] = $this->parseKey($key);

        if ($scope === ConfigScope::Local) {
            return $this->readLocal($section, $property);
        }

        if ($scope === ConfigScope::Global) {
            return $this->readGlobal($section, $property);
        }

        // No scope: local > global fallback
        $value = $this->readLocal($section, $property);
        if ($value !== null) {
            return $value;
        }

        return $this->readGlobal($section, $property);
    }

    public function set(string $key, string $value, ConfigScope $scope): void
    {
        [$section, $property] = $this->parseKey($key);
        $writer = new GitConfigWriter();

        $writer->set($this->resolveConfigPath($scope), $section, $property, $value);
    }

    public function unsetKey(string $key, ConfigScope $scope): void
    {
        [$section, $property] = $this->parseKey($key);
        $writer = new GitConfigWriter();

        $writer->unsetKey($this->resolveConfigPath($scope), $section, $property);
    }

    /**
     * List all config values, merging global and local (local wins).
     *
     * @return array<string, string>
     */
    public function list(?ConfigScope $scope = null): array
    {
        $result = [];

        if ($scope !== ConfigScope::Local) {
            $globalPath = $this->globalConfigPath();
            if ($globalPath !== null) {
                $result = $this->flattenSections(new GitConfigReader($globalPath));
            }
        }

        if ($scope !== ConfigScope::Global && $this->gitDir !== null) {
            $localPath = $this->gitDir . '/config';
            $local = $this->flattenSections(new GitConfigReader($localPath));
            $result = array_merge($result, $local);
        }

        return $result;
    }

    /**
     * Parse a dotted key into [section, property].
     *
     * "user.name" → ["user", "name"]
     * "branch.main.remote" → ['branch "main"', "remote"]
     *
     * @return array{string, string}
     */
    public function parseKey(string $key): array
    {
        $parts = explode('.', $key);

        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        if (count($parts) === 3) {
            return [$parts[0] . ' "' . $parts[1] . '"', $parts[2]];
        }

        throw new PureGitException(sprintf('Invalid config key: %s', $key));
    }

    private function readLocal(string $section, string $property): ?string
    {
        if ($this->gitDir === null) {
            return null;
        }

        $reader = new GitConfigReader($this->gitDir . '/config');

        return $reader->get($section, $property);
    }

    private function readGlobal(string $section, string $property): ?string
    {
        $path = $this->globalConfigPath();
        if ($path === null) {
            return null;
        }

        $reader = new GitConfigReader($path);

        return $reader->get($section, $property);
    }

    private function globalConfigPath(): ?string
    {
        $home = getenv('HOME');
        if ($home === false) {
            return null;
        }

        return $home . '/.gitconfig';
    }

    private function resolveConfigPath(ConfigScope $scope): string
    {
        if ($scope === ConfigScope::Local) {
            if ($this->gitDir === null) {
                throw new PureGitException('Cannot use --local outside a git repository');
            }

            return $this->gitDir . '/config';
        }

        $path = $this->globalConfigPath();
        if ($path === null) {
            throw new PureGitException('Cannot determine HOME directory for global config');
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function flattenSections(GitConfigReader $reader): array
    {
        $result = [];

        foreach ($reader->getAll() as $section => $values) {
            $prefix = $this->sectionToPrefix($section);
            foreach ($values as $key => $value) {
                $result[$prefix . '.' . $key] = $value;
            }
        }

        return $result;
    }

    private function sectionToPrefix(string $section): string
    {
        // 'branch "main"' → 'branch.main'
        if (preg_match('/^(\S+)\s+"(.+)"$/', $section, $matches) === 1) {
            return $matches[1] . '.' . $matches[2];
        }

        return $section;
    }
}
