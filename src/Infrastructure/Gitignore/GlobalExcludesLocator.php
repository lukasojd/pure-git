<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Gitignore;

/**
 * Locates the global gitignore excludes file.
 */
final class GlobalExcludesLocator
{
    public static function find(string $gitDir): ?string
    {
        $home = self::getHomeDir();
        $path = self::findExcludesInConfig($gitDir, $home);

        if ($path !== null) {
            return $path;
        }

        if ($home === null) {
            return null;
        }

        $xdgHome = \is_string($_SERVER['XDG_CONFIG_HOME'] ?? null) ? $_SERVER['XDG_CONFIG_HOME'] : $home . '/.config';

        return $xdgHome . '/git/ignore';
    }

    private static function getHomeDir(): ?string
    {
        $home = $_SERVER['HOME'] ?? null;

        return \is_string($home) ? $home : null;
    }

    private static function findExcludesInConfig(string $gitDir, ?string $home): ?string
    {
        $path = self::readConfigExcludesFile($gitDir . '/config');

        if ($path === null && $home !== null) {
            $path = self::readConfigExcludesFile($home . '/.gitconfig');
        }

        if ($path === null) {
            return null;
        }

        return str_starts_with($path, '~/') && $home !== null ? $home . substr($path, 1) : $path;
    }

    private static function readConfigExcludesFile(string $configPath): ?string
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
}
