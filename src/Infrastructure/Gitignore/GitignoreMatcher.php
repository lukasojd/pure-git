<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Gitignore;

/**
 * Evaluates .gitignore rules for a working tree.
 *
 * Loads global excludes + .git/info/exclude + root .gitignore eagerly.
 * Subdirectory .gitignore files are loaded lazily on first access.
 */
final class GitignoreMatcher
{
    /**
     * @var list<GitignoreRule>
     */
    private array $rules = [];

    /**
     * @var array<string, bool> dirs already checked for .gitignore
     */
    private array $checkedDirs = [];

    public function __construct(
        private readonly string $workDir,
        string $gitDir,
    ) {
        // Global excludes first (lowest priority)
        $globalExcludes = $this->findGlobalExcludesFile($gitDir);
        if ($globalExcludes !== null && file_exists($globalExcludes)) {
            $this->loadFile($globalExcludes, '');
        }

        // info/exclude
        $excludePath = $gitDir . '/info/exclude';
        if (file_exists($excludePath)) {
            $this->loadFile($excludePath, '');
        }

        // Root .gitignore (highest eager priority)
        $rootIgnore = $workDir . '/.gitignore';
        if (file_exists($rootIgnore)) {
            $this->loadFile($rootIgnore, '');
        }
    }

    public function isIgnored(string $relativePath, bool $isDirectory = false): bool
    {
        // .git is always ignored
        if ($relativePath === '.git' || str_starts_with($relativePath, '.git/')) {
            return true;
        }

        $this->ensureParentRulesLoaded($relativePath);

        if ($this->isParentIgnored($relativePath)) {
            return true;
        }

        return $this->matchesRules($relativePath, $isDirectory);
    }

    /**
     * Walk the working tree, pruning ignored directories.
     *
     * @return list<string> relative paths of non-ignored files
     */
    public function walkWorkingTree(): array
    {
        $files = [];
        $this->walkDir('', $files);
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private function walkDir(string $relativePath, array &$files): void
    {
        $fullPath = $relativePath === '' ? $this->workDir : $this->workDir . '/' . $relativePath;
        $handle = opendir($fullPath);
        if ($handle === false) {
            return;
        }

        if ($relativePath !== '') {
            $this->loadDirChain($relativePath);
        }

        while (($item = readdir($handle)) !== false) {
            if (! in_array($item, ['.', '..', '.git'], true)) {
                $this->walkItem($relativePath, $fullPath, $item, $files);
            }
        }

        closedir($handle);
    }

    /**
     * @param list<string> $files
     */
    private function walkItem(string $relativePath, string $fullPath, string $item, array &$files): void
    {
        $itemRelative = $relativePath === '' ? $item : $relativePath . '/' . $item;

        if (is_dir($fullPath . '/' . $item)) {
            if (! $this->matchesRules($itemRelative, true)) {
                $this->walkDir($itemRelative, $files);
            }
        } elseif (! $this->matchesRules($itemRelative, false)) {
            $files[] = $itemRelative;
        }
    }

    private function matchesRules(string $path, bool $isDirectory): bool
    {
        $ignored = false;
        foreach ($this->rules as $rule) {
            if ($rule->matches($path, $isDirectory)) {
                $ignored = ! $rule->negation;
            }
        }

        return $ignored;
    }

    private function isParentIgnored(string $relativePath): bool
    {
        $parts = explode('/', $relativePath);
        array_pop($parts);
        $dir = '';

        foreach ($parts as $part) {
            $dir = ltrim($dir . '/' . $part, '/');
            if ($this->matchesRules($dir, true)) {
                return true;
            }
        }

        return false;
    }

    private function ensureParentRulesLoaded(string $relativePath): void
    {
        $slash = strrpos($relativePath, '/');
        if ($slash === false) {
            return;
        }

        $dir = substr($relativePath, 0, $slash);
        $this->loadDirChain($dir);
    }

    private function loadDirChain(string $dir): void
    {
        $parts = explode('/', $dir);
        $path = '';

        foreach ($parts as $part) {
            $path = $path === '' ? $part : $path . '/' . $part;

            if (isset($this->checkedDirs[$path])) {
                continue;
            }

            $this->checkedDirs[$path] = true;
            $gitignorePath = $this->workDir . '/' . $path . '/.gitignore';

            if (file_exists($gitignorePath)) {
                $this->loadFile($gitignorePath, $path);
            }
        }
    }

    private function findGlobalExcludesFile(string $gitDir): ?string
    {
        $home = $this->getHomeDir();
        $path = $this->findExcludesInConfig($gitDir, $home);

        if ($path !== null) {
            return $path;
        }

        if ($home === null) {
            return null;
        }

        $xdgHome = \is_string($_SERVER['XDG_CONFIG_HOME'] ?? null) ? $_SERVER['XDG_CONFIG_HOME'] : $home . '/.config';

        return $xdgHome . '/git/ignore';
    }

    private function getHomeDir(): ?string
    {
        $home = $_SERVER['HOME'] ?? null;

        return \is_string($home) ? $home : null;
    }

    private function findExcludesInConfig(string $gitDir, ?string $home): ?string
    {
        $path = $this->readConfigExcludesFile($gitDir . '/config');

        if ($path === null && $home !== null) {
            $path = $this->readConfigExcludesFile($home . '/.gitconfig');
        }

        if ($path === null) {
            return null;
        }

        return str_starts_with($path, '~/') && $home !== null ? $home . substr($path, 1) : $path;
    }

    private function readConfigExcludesFile(string $configPath): ?string
    {
        if (! file_exists($configPath)) {
            return null;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return null;
        }

        if (preg_match('/^\[core\]\s*$(.+?)(?=^\[|\z)/ms', $content, $section) !== 1) {
            return null;
        }

        if (preg_match('/^\s*excludes[Ff]ile\s*=\s*(.+?)\s*$/m', $section[1], $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function loadFile(string $filePath, string $scope): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        foreach (explode("\n", $content) as $line) {
            $rule = $this->parseLine($line, $scope);
            if ($rule instanceof GitignoreRule) {
                $this->rules[] = $rule;
            }
        }
    }

    private function parseLine(string $line, string $scope): ?GitignoreRule
    {
        $line = rtrim($line);
        if ($line === '' || $line[0] === '#') {
            return null;
        }

        $negation = false;
        if ($line[0] === '!') {
            $negation = true;
            $line = substr($line, 1);
        }

        $directoryOnly = false;
        if (str_ends_with($line, '/')) {
            $directoryOnly = true;
            $line = rtrim($line, '/');
        }

        if ($line === '') {
            return null;
        }

        return new GitignoreRule(
            negation: $negation,
            directoryOnly: $directoryOnly,
            scope: $scope,
            pattern: $line,
        );
    }
}
