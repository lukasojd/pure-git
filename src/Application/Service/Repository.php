<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Application\Service;

use Lukasojd\PureGit\Domain\CommitGraph\CommitGraphInterface;
use Lukasojd\PureGit\Domain\Exception\RepositoryException;
use Lukasojd\PureGit\Domain\Repository\IndexStorageInterface;
use Lukasojd\PureGit\Domain\Repository\ObjectStorageInterface;
use Lukasojd\PureGit\Domain\Repository\RefStorageInterface;
use Lukasojd\PureGit\Infrastructure\Cache\ObjectCache;
use Lukasojd\PureGit\Infrastructure\CommitGraph\CommitGraphReader;
use Lukasojd\PureGit\Infrastructure\Filesystem\FilesystemInterface;
use Lukasojd\PureGit\Infrastructure\Filesystem\LocalFilesystem;
use Lukasojd\PureGit\Infrastructure\Gitignore\GitignoreMatcher;
use Lukasojd\PureGit\Infrastructure\Index\IndexFileHandler;
use Lukasojd\PureGit\Infrastructure\Object\CombinedObjectStorage;
use Lukasojd\PureGit\Infrastructure\Object\LooseObjectStorage;
use Lukasojd\PureGit\Infrastructure\Ref\FileRefStorage;

final readonly class Repository
{
    public ObjectStorageInterface $objects;

    public RefStorageInterface $refs;

    public IndexStorageInterface $index;

    public FilesystemInterface $filesystem;

    public ?CommitGraphInterface $commitGraph;

    public ?GitignoreMatcher $gitignore;

    private function __construct(
        public string $workDir,
        public string $gitDir
    ) {
        $this->filesystem = new LocalFilesystem();

        $looseStorage = new LooseObjectStorage($this->gitDir . '/objects');
        $cache = new ObjectCache();
        $this->objects = new CombinedObjectStorage($looseStorage, $this->gitDir . '/objects', $cache);
        $this->refs = new FileRefStorage($this->gitDir);
        $this->index = new IndexFileHandler($this->gitDir . '/index');
        $this->commitGraph = $this->loadCommitGraph();
        $this->gitignore = ($workDir !== $gitDir) ? new GitignoreMatcher($workDir, $gitDir) : null;
    }

    public static function init(string $path): self
    {
        $gitDir = $path . '/.git';

        if (is_dir($gitDir)) {
            throw RepositoryException::alreadyExists($path);
        }

        $dirs = [
            $gitDir,
            $gitDir . '/objects',
            $gitDir . '/objects/pack',
            $gitDir . '/refs',
            $gitDir . '/refs/heads',
            $gitDir . '/refs/tags',
        ];

        foreach ($dirs as $dir) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($gitDir . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($gitDir . '/config', "[core]\n\trepositoryformatversion = 0\n\tfilemode = true\n\tbare = false\n");
        file_put_contents($gitDir . '/description', "Unnamed repository; edit this file to name the repository.\n");

        return new self($path, $gitDir);
    }

    public static function open(string $path): self
    {
        $gitDir = $path . '/.git';
        if (! is_dir($gitDir)) {
            // Maybe it's a bare repo
            if (is_dir($path . '/objects') && is_dir($path . '/refs')) {
                return new self($path, $path);
            }
            throw RepositoryException::notARepository($path);
        }

        return new self($path, $gitDir);
    }

    public static function discover(string $startPath): self
    {
        $current = realpath($startPath);
        if ($current === false) {
            throw RepositoryException::notARepository($startPath);
        }

        while (true) {
            if (is_dir($current . '/.git')) {
                return self::open($current);
            }

            $parent = dirname($current);
            if ($parent === $current) {
                throw RepositoryException::notARepository($startPath);
            }
            $current = $parent;
        }
    }

    private function loadCommitGraph(): ?CommitGraphInterface
    {
        $graphPath = $this->gitDir . '/objects/info/commit-graph';
        if (! file_exists($graphPath)) {
            return null;
        }

        try {
            return new CommitGraphReader($graphPath);
        } catch (\Throwable) {
            return null;
        }
    }
}
