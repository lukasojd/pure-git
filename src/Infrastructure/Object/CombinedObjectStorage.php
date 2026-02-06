<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\ObjectNotFoundException;
use Lukasojd\PureGit\Domain\Exception\PureGitException;
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
            $raw = $reader->tryReadObject($id);
            if ($raw !== null) {
                return $raw;
            }
        }

        throw ObjectNotFoundException::withId($id->hash);
    }

    public function readRawHeader(ObjectId $id): RawObject
    {
        return $this->readRawHeaderByBinary($id->toBinary());
    }

    public function readRawHeaderByBinary(string $binHash): RawObject
    {
        // Check packs first — avoids file_exists() for the common case where
        // all objects are packed. Safe because objects are content-addressed.
        $this->ensurePacksLoaded();
        foreach ($this->packReaders as $reader) {
            $raw = $reader->tryReadObjectHeaderByBinary($binHash);
            if ($raw !== null) {
                return $raw;
            }
        }

        return $this->looseStorage->readRawHeaderByBinary($binHash);
    }

    public function findByPrefix(string $hexPrefix): ?ObjectId
    {
        $matches = $this->findLooseByPrefix($hexPrefix);

        $this->ensurePacksLoaded();
        foreach ($this->packReaders as $reader) {
            $matches = array_merge($matches, $reader->findByPrefix($hexPrefix));
            if (count($matches) > 1) {
                throw new PureGitException(sprintf('short object ID %s is ambiguous', $hexPrefix));
            }
        }

        if ($matches === []) {
            return null;
        }

        return ObjectId::fromHex($matches[0]);
    }

    public function refreshPacks(): void
    {
        $this->packReaders = [];
        $this->packsLoaded = false;
    }

    /**
     * @return list<string> hex hashes
     */
    private function findLooseByPrefix(string $hexPrefix): array
    {
        if (strlen($hexPrefix) < 2) {
            return [];
        }

        $dir = $this->objectsDir . '/' . substr($hexPrefix, 0, 2);
        if (! is_dir($dir)) {
            return [];
        }

        $suffix = substr($hexPrefix, 2);
        $matches = [];
        $files = scandir($dir);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && str_starts_with($file, $suffix)) {
                $matches[] = substr($hexPrefix, 0, 2) . $file;
            }
        }

        return $matches;
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
