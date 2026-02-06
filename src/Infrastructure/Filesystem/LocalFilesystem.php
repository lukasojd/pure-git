<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Filesystem;

use Lukasojd\PureGit\Domain\Exception\PureGitException;

final class LocalFilesystem implements FilesystemInterface
{
    public function read(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new PureGitException(sprintf('Cannot read file: %s', $path));
        }

        return $content;
    }

    public function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        if (file_put_contents($path, $content) === false) {
            throw new PureGitException(sprintf('Cannot write file: %s', $path));
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function delete(string $path): void
    {
        if (is_dir($path)) {
            $this->deleteDirectory($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    public function mkdir(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0o777, true);
        }
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    /**
     * @return list<string>
     */
    public function listDirectory(string $path): array
    {
        $items = scandir($path);
        if ($items === false) {
            throw new PureGitException(sprintf('Cannot list directory: %s', $path));
        }

        $result = [];
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function listFilesRecursive(string $path): array
    {
        $files = [];
        $this->collectFiles($path, '', $files);
        sort($files);

        return $files;
    }

    public function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $tmpPath = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmpPath, $content) === false) {
            throw new PureGitException(sprintf('Cannot write temp file: %s', $tmpPath));
        }

        rename($tmpPath, $path);
    }

    public function rename(string $from, string $to): void
    {
        $dir = dirname($to);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        rename($from, $to);
    }

    public function fileSize(string $path): int
    {
        $size = filesize($path);
        if ($size === false) {
            throw new PureGitException(sprintf('Cannot get file size: %s', $path));
        }

        return $size;
    }

    public function modifiedTime(string $path): int
    {
        $time = filemtime($path);
        if ($time === false) {
            throw new PureGitException(sprintf('Cannot get modified time: %s', $path));
        }

        return $time;
    }

    /**
     * @param list<string> $files
     */
    private function collectFiles(string $basePath, string $relativePath, array &$files): void
    {
        $fullPath = $relativePath === '' ? $basePath : $basePath . '/' . $relativePath;
        $items = scandir($fullPath);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemRelative = $relativePath === '' ? $item : $relativePath . '/' . $item;
            $itemFull = $basePath . '/' . $itemRelative;

            if (is_dir($itemFull)) {
                $this->collectFiles($basePath, $itemRelative, $files);
            } else {
                $files[] = $itemRelative;
            }
        }
    }

    private function deleteDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->deleteDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
