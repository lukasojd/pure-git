<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Handler;

use Lukasojd\PureGit\Application\Service\Repository;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
use Lukasojd\PureGit\Support\PathUtils;

final readonly class RmHandler
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    /**
     * @param list<string> $paths
     */
    public function handle(array $paths, bool $cached = false): void
    {
        $index = $this->repository->index->read();

        foreach ($paths as $path) {
            $path = PathUtils::normalize($path);

            if (! $index->hasEntry($path)) {
                throw new PureGitException(sprintf('pathspec \'%s\' did not match any files', $path));
            }

            $index->removeEntry($path);

            if (! $cached) {
                $fullPath = $this->repository->workDir . '/' . $path;
                if ($this->repository->filesystem->exists($fullPath)) {
                    $this->repository->filesystem->delete($fullPath);
                }
            }
        }

        $this->repository->index->write($index);
    }
}
