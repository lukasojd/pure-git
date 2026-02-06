<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Config;

/**
 * Reads git config files (.git/config).
 *
 * Supports section headers like [core], [branch "main"], [remote "origin"].
 */
final class GitConfigReader
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $sections = [];

    public function __construct(string $configPath)
    {
        if (file_exists($configPath)) {
            $this->parse($configPath);
        }
    }

    public function get(string $section, string $key): ?string
    {
        return $this->sections[$section][$key] ?? null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getAll(): array
    {
        return $this->sections;
    }

    /**
     * @return list<string>
     */
    public function listRemotes(): array
    {
        $remotes = [];
        foreach (array_keys($this->sections) as $section) {
            if (preg_match('/^remote "(.+)"$/', $section, $matches) === 1) {
                $remotes[] = $matches[1];
            }
        }

        return $remotes;
    }

    /**
     * Get the upstream remote-tracking ref for a branch.
     *
     * Reads [branch "X"] remote + merge, returns "refs/remotes/{remote}/{branch}".
     */
    public function getUpstreamRef(string $branchName): ?string
    {
        $section = 'branch "' . $branchName . '"';
        $remote = $this->get($section, 'remote');
        $merge = $this->get($section, 'merge');

        if ($remote === null || $merge === null) {
            return null;
        }

        // merge is typically "refs/heads/X" — convert to "refs/remotes/{remote}/X"
        $shortBranch = str_starts_with($merge, 'refs/heads/')
            ? substr($merge, strlen('refs/heads/'))
            : $merge;

        return 'refs/remotes/' . $remote . '/' . $shortBranch;
    }

    private function parse(string $configPath): void
    {
        $content = file_get_contents($configPath);
        if ($content === false) {
            return;
        }

        $currentSection = '';

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }

            if ($trimmed[0] === '[') {
                $currentSection = $this->parseSectionHeader($trimmed);
                continue;
            }

            $this->parseKeyValue($currentSection, $trimmed);
        }
    }

    private function parseSectionHeader(string $line): string
    {
        // [core] → "core", [branch "main"] → 'branch "main"'
        $line = rtrim($line, ']');
        $line = ltrim($line, '[');

        return trim($line);
    }

    private function parseKeyValue(string $section, string $line): void
    {
        $pos = strpos($line, '=');
        if ($pos === false) {
            return;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        $this->sections[$section][$key] = $value;
    }
}
