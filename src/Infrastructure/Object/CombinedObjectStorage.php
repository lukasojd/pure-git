<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\ObjectNotFoundException;
use Lukasojd\PureGit\Domain\Object\GitObject;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Domain\Repository\ObjectStorageInterface;
use Lukasojd\PureGit\Domain\Repository\RawObject;
use Lukasojd\PureGit\Infrastructure\Cache\ObjectCache;

final class CombinedObjectStorage implements ObjectStorageInterface
{
    /**
     * @var list<PackfileReader>
     */
    private array $packReaders = [];

    private bool $packsLoaded = false;

    public function __construct(
        private readonly LooseObjectStorage $looseStorage,
        private readonly string $objectsDir,
        private readonly ObjectCache $cache,
    ) {
    }

    public function read(ObjectId $id): GitObject
    {
        $cached = $this->cache->get($id);
        if ($cached instanceof GitObject) {
            return $cached;
        }

        $raw = $this->readRaw($id);
        $object = LooseObjectStorage::deserialize($raw);
        $this->cache->set($id, $object);

        return $object;
    }

    public function write(GitObject $object): ObjectId
    {
        $id = $this->looseStorage->write($object);
        $this->cache->set($id, $object);

        return $id;
    }

    public function exists(ObjectId $id): bool
    {
        if ($this->cache->has($id)) {
            return true;
        }

        if ($this->looseStorage->exists($id)) {
            return true;
        }

        $this->ensurePacksLoaded();
        return array_any($this->packReaders, fn ($reader) => $reader->hasObject($id));
    }

    public function readRaw(ObjectId $id): RawObject
    {
        if ($this->looseStorage->exists($id)) {
            return $this->looseStorage->readRaw($id);
        }

        $this->ensurePacksLoaded();
        foreach ($this->packReaders as $reader) {
            if ($reader->hasObject($id)) {
                return $reader->readObject($id);
            }
        }

        throw ObjectNotFoundException::withId($id->hash);
    }

    private function ensurePacksLoaded(): void
    {
        if ($this->packsLoaded) {
            return;
        }

        $this->loadPackFiles();
        $this->packsLoaded = true;
    }

    private function loadPackFiles(): void
    {
        $packDir = $this->objectsDir . '/pack';
        if (! is_dir($packDir)) {
            return;
        }

        $files = scandir($packDir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $this->loadPackFileIfValid($packDir, $file);
        }
    }

    private function loadPackFileIfValid(string $packDir, string $file): void
    {
        if (! str_ends_with($file, '.idx')) {
            return;
        }

        $baseName = substr($file, 0, -4);
        $packPath = $packDir . '/' . $baseName . '.pack';
        $idxPath = $packDir . '/' . $file;

        if (! file_exists($packPath)) {
            return;
        }

        $indexReader = new PackIndexReader($idxPath);
        $this->packReaders[] = new PackfileReader($packPath, $indexReader);
    }
}
