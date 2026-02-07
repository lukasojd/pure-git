<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Index\IndexEntry;
use Lukasojd\PureGit\Domain\Object\Blob;
use Lukasojd\PureGit\Domain\Object\FileMode;
use Lukasojd\PureGit\Support\PathUtils;

final readonly class AddHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function updateTracked(): void
    {
        $index = $this->repository->index->read();
        $indexFileTime = @filemtime($this->repository->gitDir . '/index');
        $indexMtime = $indexFileTime !== false ? $indexFileTime : 0;

        foreach ($index->getEntries() as $path => $entry) {
            $fullPath = $this->repository->workDir . '/' . $path;
            if (! file_exists($fullPath)) {
                $index->removeEntry($path);
                continue;
            }

            $stat = stat($fullPath);
            if ($stat === false) {
                continue;
            }

            if ($this->isStatClean($entry, $stat, $indexMtime)) {
                continue;
            }

            $content = $this->repository->filesystem->read($fullPath);
            $blob = new Blob($content);
            if (! $blob->getId()->equals($entry->objectId)) {
                $this->repository->objects->write($blob);
                $newEntry = IndexEntry::createFromStat($path, $blob->getId(), $entry->mode, $stat);
                $index->addEntry($newEntry);
            }
        }

        $this->repository->index->write($index);
    }

    /**
     * @param list<string> $paths relative paths to add
     */
    public function handle(array $paths): void
    {
        $index = $this->repository->index->read();

        foreach ($paths as $path) {
            $path = PathUtils::normalize($path);
            PathUtils::validateRelativePath($path);

            $fullPath = $this->repository->workDir . '/' . $path;

            if ($this->repository->filesystem->isDirectory($fullPath)) {
                $this->addDirectory($index, $path);
            } elseif ($this->repository->filesystem->isFile($fullPath)) {
                $this->addFile($index, $path);
            } elseif ($index->hasEntry($path)) {
                // File was deleted — remove from index
                $index->removeEntry($path);
            }
        }

        $this->repository->index->write($index);
    }

    /**
     * @param array{mtime: int, size: int} $stat
     */
    private function isStatClean(IndexEntry $entry, array $stat, int $indexMtime): bool
    {
        if ($entry->mtime === 0) {
            return false;
        }

        if ($stat['mtime'] !== $entry->mtime || $stat['size'] !== $entry->fileSize) {
            return false;
        }

        return $entry->mtime < $indexMtime;
    }

    private function addDirectory(\Lukasojd\PureGit\Domain\Index\Index $index, string $relativePath): void
    {
        $fullPath = $this->repository->workDir . '/' . $relativePath;
        $files = $this->repository->filesystem->listFilesRecursive($fullPath);
        $gitignore = $this->repository->gitignore;

        foreach ($files as $file) {
            $filePath = $relativePath . '/' . $file;
            if ($gitignore instanceof \Lukasojd\PureGit\Infrastructure\Gitignore\GitignoreMatcher && $gitignore->isIgnored($filePath)) {
                continue;
            }
            $this->addFile($index, $filePath);
        }
    }

    private function addFile(\Lukasojd\PureGit\Domain\Index\Index $index, string $relativePath): void
    {
        $fullPath = $this->repository->workDir . '/' . $relativePath;
        $content = $this->repository->filesystem->read($fullPath);
        $blob = new Blob($content);
        $this->repository->objects->write($blob);

        $stat = stat($fullPath);
        $mode = is_executable($fullPath) ? FileMode::Executable : FileMode::Regular;

        $entry = $stat !== false
            ? IndexEntry::createFromStat($relativePath, $blob->getId(), $mode, $stat)
            : IndexEntry::create($relativePath, $blob->getId(), $mode, strlen($content));
        $index->addEntry($entry);
    }
}
