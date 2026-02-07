<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Gitignore;

/**
 * A single parsed .gitignore rule with pre-compiled regex.
 */
final readonly class GitignoreRule
{
    private string $regex;

    private bool $basenameOnly;

    private ?string $literalPattern;

    public function __construct(
        public bool $negation,
        public bool $directoryOnly,
        public string $scope,
        string $pattern,
    ) {
        $this->basenameOnly = ! str_contains($pattern, '/');
        $this->literalPattern = $this->detectLiteral($pattern);
        $this->regex = $this->compilePattern($pattern);
    }

    public function matches(string $relativePath, string $basename, bool $isDirectory): bool
    {
        if ($this->directoryOnly && ! $isDirectory) {
            return false;
        }

        if ($this->scope !== '' && ! str_starts_with($relativePath, $this->scope . '/')) {
            return false;
        }

        if ($this->basenameOnly) {
            return $this->matchAgainst($basename);
        }

        $pathToMatch = $this->scope !== '' ? substr($relativePath, strlen($this->scope) + 1) : $relativePath;

        return $this->matchAgainst($pathToMatch);
    }

    private function matchAgainst(string $value): bool
    {
        if ($this->literalPattern !== null) {
            return $value === $this->literalPattern;
        }

        return preg_match($this->regex, $value) === 1;
    }

    private function detectLiteral(string $pattern): ?string
    {
        $clean = ltrim($pattern, '/');
        if ($clean === '' || preg_match('/[*?[\\\\]/', $clean) === 1) {
            return null;
        }

        return $clean;
    }

    private function compilePattern(string $pattern): string
    {
        $anchored = str_starts_with($pattern, '/');
        if ($anchored) {
            $pattern = substr($pattern, 1);
        }

        $regex = $this->globToRegex($pattern);

        return $anchored ? '#^' . $regex . '$#' : '#(?:^|/)' . $regex . '$#';
    }

    private function globToRegex(string $pattern): string
    {
        $result = '';
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $result .= $this->convertChar($pattern, $len, $i);
        }

        return $result;
    }

    private function convertChar(string $pattern, int $len, int &$i): string
    {
        $ch = $pattern[$i];

        if ($ch === '*') {
            return $this->convertStar($pattern, $len, $i);
        }

        $i++;

        return match ($ch) {
            '?' => '[^/]',
            '[' => $this->convertBracket($pattern, $len, $i),
            '\\' => ($i < $len) ? preg_quote($pattern[$i++], '#') : '\\\\',
            default => preg_quote($ch, '#'),
        };
    }

    private function convertStar(string $pattern, int $len, int &$i): string
    {
        if ($i + 1 < $len && $pattern[$i + 1] === '*') {
            $i += 2;
            // **/ or ** at end
            if ($i < $len && $pattern[$i] === '/') {
                $i++;

                return '(?:.+/)?';
            }

            return '.*';
        }

        $i++;

        return '[^/]*';
    }

    private function convertBracket(string $pattern, int $len, int &$i): string
    {
        $bracket = '[';
        // Back up: $i is now past the '['
        while ($i < $len && $pattern[$i] !== ']') {
            $bracket .= $pattern[$i++];
        }

        if ($i < $len) {
            $bracket .= $pattern[$i++]; // consume ']'
        }

        return $bracket;
    }
}
