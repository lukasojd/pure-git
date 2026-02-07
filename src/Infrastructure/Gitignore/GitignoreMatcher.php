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

    private string $combinedBasenameRegex = '';

    private string $combinedPathRegex = '';

    public function __construct(
        private readonly string $workDir,
        string $gitDir,
    ) {
        $globalExcludes = GlobalExcludesLocator::find($gitDir);
        if ($globalExcludes !== null) {
            $this->loadFileIfExists($globalExcludes, '');
        }

        $this->loadFileIfExists($gitDir . '/info/exclude', '');
        $this->loadFileIfExists($workDir . '/.gitignore', '');
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

        $slash = strrpos($relativePath, '/');
        $basename = $slash !== false ? substr($relativePath, $slash + 1) : $relativePath;

        return $this->matchesRules($relativePath, $basename, $isDirectory);
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
            $this->loadDirIgnoreIfNeeded($relativePath);
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
            if (! $this->matchesRules($itemRelative, $item, true)) {
                $this->walkDir($itemRelative, $files);
            }
        } elseif (! $this->matchesRules($itemRelative, $item, false)) {
            $files[] = $itemRelative;
        }
    }

    private function loadDirIgnoreIfNeeded(string $relativePath): void
    {
        if (isset($this->checkedDirs[$relativePath])) {
            return;
        }

        $this->checkedDirs[$relativePath] = true;
        $gitignorePath = $this->workDir . '/' . $relativePath . '/.gitignore';

        if (file_exists($gitignorePath)) {
            $this->loadFile($gitignorePath, $relativePath);
        }
    }

    private function matchesRules(string $path, string $basename, bool $isDirectory): bool
    {
        if (! $this->couldMatchAnyRule($basename, $path)) {
            return false;
        }

        $ignored = false;
        foreach ($this->rules as $rule) {
            if ($rule->matches($path, $basename, $isDirectory)) {
                $ignored = ! $rule->negation;
            }
        }

        return $ignored;
    }

    private function couldMatchAnyRule(string $basename, string $path): bool
    {
        if ($this->combinedBasenameRegex !== '' && preg_match($this->combinedBasenameRegex, $basename) === 1) {
            return true;
        }

        return $this->combinedPathRegex !== '' && preg_match($this->combinedPathRegex, $path) === 1;
    }

    private function rebuildFastPath(): void
    {
        $basenameFragments = [];
        $pathFragments = [];

        foreach ($this->rules as $rule) {
            if ($rule->basenameOnly) {
                $basenameFragments[] = $rule->regexFragment;
            } else {
                $pathFragments[] = $this->buildPathFragment($rule);
            }
        }

        $this->combinedBasenameRegex = $basenameFragments !== []
            ? '#^(?:' . implode('|', $basenameFragments) . ')$#'
            : '';
        $this->combinedPathRegex = $pathFragments !== []
            ? '#(?:' . implode('|', $pathFragments) . ')#'
            : '';
    }

    private function buildPathFragment(GitignoreRule $rule): string
    {
        $fragment = $rule->scope !== ''
            ? $rule->scope . '/' . $rule->regexFragment
            : $rule->regexFragment;

        return $rule->anchored ? '^' . $fragment . '$' : '(?:^|/)' . $fragment . '$';
    }

    private function isParentIgnored(string $relativePath): bool
    {
        $parts = explode('/', $relativePath);
        array_pop($parts);
        $dir = '';

        foreach ($parts as $part) {
            $dir = ltrim($dir . '/' . $part, '/');
            if ($this->matchesRules($dir, $part, true)) {
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
            $path = ltrim($path . '/' . $part, '/');
            $this->loadDirIgnoreIfNeeded($path);
        }
    }

    private function loadFileIfExists(string $path, string $scope): void
    {
        if (file_exists($path)) {
            $this->loadFile($path, $scope);
        }
    }

    private function loadFile(string $filePath, string $scope): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        foreach (explode("\n", $content) as $line) {
            $rule = GitignoreRule::fromLine($line, $scope);
            if ($rule instanceof GitignoreRule) {
                $this->rules[] = $rule;
            }
        }

        $this->rebuildFastPath();
    }
}
