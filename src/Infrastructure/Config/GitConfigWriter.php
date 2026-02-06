<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Config;

final class GitConfigWriter
{
    /**
     * Set a config value. Creates the file/section/key as needed.
     */
    public function set(string $configPath, string $section, string $key, string $value): void
    {
        $lines = $this->readLines($configPath);
        $range = $this->findSectionRange($lines, $section);

        if ($range === null) {
            $lines[] = $this->formatSectionHeader($section);
            $lines[] = "\t" . $key . ' = ' . $value;
            $this->writeLines($configPath, $lines);

            return;
        }

        [$sectionStart, $sectionEnd] = $range;
        $keyLine = $this->findKeyInRange($lines, $sectionStart, $sectionEnd, $key);

        if ($keyLine !== null) {
            $lines[$keyLine] = "\t" . $key . ' = ' . $value;
        } else {
            array_splice($lines, $sectionEnd + 1, 0, ["\t" . $key . ' = ' . $value]);
        }

        $this->writeLines($configPath, array_values($lines));
    }

    /**
     * Remove a config key. Returns false if the key was not found.
     */
    public function unsetKey(string $configPath, string $section, string $key): bool
    {
        $lines = $this->readLines($configPath);
        $range = $this->findSectionRange($lines, $section);

        if ($range === null) {
            return false;
        }

        [$sectionStart, $sectionEnd] = $range;
        $keyLine = $this->findKeyInRange($lines, $sectionStart, $sectionEnd, $key);

        if ($keyLine === null) {
            return false;
        }

        array_splice($lines, $keyLine, 1);
        $this->writeLines($configPath, $lines);

        return true;
    }

    /**
     * @return list<string>
     */
    private function readLines(string $configPath): array
    {
        if (! file_exists($configPath)) {
            return [];
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return [];
        }

        // Remove trailing newline to avoid empty trailing element
        $content = rtrim($content, "\n");
        if ($content === '') {
            return [];
        }

        return explode("\n", $content);
    }

    /**
     * @param list<string> $lines
     */
    private function writeLines(string $configPath, array $lines): void
    {
        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($configPath, implode("\n", $lines) . "\n");
    }

    /**
     * Find the line range [start, end] of a section (inclusive).
     * Returns null if the section does not exist.
     *
     * @param list<string> $lines
     * @return array{int, int}|null
     */
    private function findSectionRange(array $lines, string $section): ?array
    {
        $sectionStart = null;
        $counter = count($lines);

        for ($i = 0; $i < $counter; $i++) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '' || $trimmed[0] !== '[') {
                continue;
            }

            $parsed = $this->parseSectionHeader($trimmed);
            if ($parsed === null) {
                continue;
            }

            if ($sectionStart !== null) {
                return [$sectionStart, $i - 1];
            }

            if ($parsed === $section) {
                $sectionStart = $i;
            }
        }

        if ($sectionStart !== null) {
            return [$sectionStart, count($lines) - 1];
        }

        return null;
    }

    /**
     * Find the line index of a key within a section range.
     *
     * @param list<string> $lines
     */
    private function findKeyInRange(array $lines, int $start, int $end, string $key): ?int
    {
        for ($i = $start + 1; $i <= $end; $i++) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }

            if ($trimmed[0] === '[') {
                return null;
            }

            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }

            $k = trim(substr($trimmed, 0, $pos));
            if ($k === $key) {
                return $i;
            }
        }

        return null;
    }

    private function formatSectionHeader(string $section): string
    {
        return '[' . $section . ']';
    }

    private function parseSectionHeader(string $line): ?string
    {
        if ($line[0] !== '[' || ! str_ends_with($line, ']')) {
            return null;
        }

        $inner = substr($line, 1, -1);

        return trim($inner);
    }
}
