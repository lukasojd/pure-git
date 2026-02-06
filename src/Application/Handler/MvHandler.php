<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Domain\Index\IndexEntry;
use Lukasojd\PureGit\Support\PathUtils;

final readonly class MvHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    public function handle(string $source, string $destination): void
    {
        $source = PathUtils::normalize($source);
        $destination = PathUtils::normalize($destination);
        PathUtils::validateRelativePath($source);
        PathUtils::validateRelativePath($destination);

        $index = $this->repository->index->read();

        $entry = $index->getEntry($source);
        if (! $entry instanceof \Lukasojd\PureGit\Domain\Index\IndexEntry) {
            throw new PureGitException(sprintf('pathspec \'%s\' did not match any files', $source));
        }

        $srcFull = $this->repository->workDir . '/' . $source;
        $dstFull = $this->repository->workDir . '/' . $destination;

        if (! $this->repository->filesystem->exists($srcFull)) {
            throw new PureGitException(sprintf('Source file does not exist: %s', $source));
        }

        $dstDir = dirname($dstFull);
        if (! is_dir($dstDir)) {
            mkdir($dstDir, 0o777, true);
        }

        $this->repository->filesystem->rename($srcFull, $dstFull);

        $newEntry = IndexEntry::create($destination, $entry->objectId, $entry->mode, $entry->fileSize);
        $index->removeEntry($source);
        $index->addEntry($newEntry);

        $this->repository->index->write($index);
    }
}
