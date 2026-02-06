<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Filesystem;

interface FilesystemInterface
{
    public function read(string $path): string;

    public function write(string $path, string $content): void;

    public function exists(string $path): bool;

    public function delete(string $path): void;

    public function mkdir(string $path): void;

    public function isDirectory(string $path): bool;

    public function isFile(string $path): bool;

    /**
     * @return list<string>
     */
    public function listDirectory(string $path): array;

    /**
     * @return list<string> all files recursively, relative to path
     */
    public function listFilesRecursive(string $path): array;

    public function atomicWrite(string $path, string $content): void;

    public function rename(string $from, string $to): void;

    public function fileSize(string $path): int;

    public function modifiedTime(string $path): int;
}
