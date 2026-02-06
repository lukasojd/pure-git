<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Support;

use Lukasojd\PureGit\Domain\Exception\PathTraversalException;

final class PathUtils
{
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        if ($path === null) {
            return '';
        }

        return rtrim($path, '/');
    }

    public static function validateNoTraversal(string $path, string $basePath): void
    {
        $realBase = realpath($basePath);
        if ($realBase === false) {
            throw PathTraversalException::detected($path);
        }

        $fullPath = $realBase . '/' . $path;
        $resolved = self::resolveWithoutSymlinks($fullPath);

        if (! str_starts_with($resolved, $realBase)) {
            throw PathTraversalException::detected($path);
        }
    }

    public static function validateRelativePath(string $path): void
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            throw PathTraversalException::detected($path);
        }

        $parts = explode('/', str_replace('\\', '/', $path));
        foreach ($parts as $part) {
            if ($part === '..') {
                throw PathTraversalException::detected($path);
            }
        }
    }

    public static function join(string ...$parts): string
    {
        return self::normalize(implode('/', $parts));
    }

    public static function relativeTo(string $path, string $basePath): string
    {
        $path = self::normalize($path);
        $basePath = rtrim(self::normalize($basePath), '/') . '/';

        if (str_starts_with($path, $basePath)) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    public static function parentDirectories(string $path): array
    {
        $parts = explode('/', self::normalize($path));
        array_pop($parts);
        $dirs = [];
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $dirs[] = $current;
        }

        return $dirs;
    }

    private static function resolveWithoutSymlinks(string $path): string
    {
        $parts = explode('/', $path);
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return '/' . implode('/', $resolved);
    }
}
