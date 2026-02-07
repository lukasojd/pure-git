<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Gitignore;

/**
 * A single parsed .gitignore rule with pre-compiled regex.
 */
final readonly class GitignoreRule
{
    public bool $basenameOnly;

    public ?string $literalPattern;

    public string $regexFragment;

    public bool $anchored;

    private string $regex;

    public function __construct(
        public bool $negation,
        public bool $directoryOnly,
        public string $scope,
        string $pattern,
    ) {
        $this->basenameOnly = ! str_contains($pattern, '/');
        $this->literalPattern = $this->detectLiteral($pattern);
        $this->anchored = str_starts_with($pattern, '/');

        $cleanPattern = $this->anchored ? substr($pattern, 1) : $pattern;
        $this->regexFragment = $this->globToRegex($cleanPattern);
        $this->regex = $this->anchored ? '#^' . $this->regexFragment . '$#' : '#(?:^|/)' . $this->regexFragment . '$#';
    }

    public static function fromLine(string $line, string $scope): ?self
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

        return new self(
            negation: $negation,
            directoryOnly: $directoryOnly,
            scope: $scope,
            pattern: $line,
        );
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
