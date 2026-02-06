<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Object;

use Lukasojd\PureGit\Domain\Exception\InvalidObjectException;
use Lukasojd\PureGit\Domain\Object\ObjectId;
use Lukasojd\PureGit\Support\BinaryReader;

final class PackIndexReader
{
    /**
     * @var array<string, int> object hash => pack offset
     */
    private array $entries = [];

    private bool $loaded = false;

    public function __construct(
        private readonly string $indexPath,
    ) {
    }

    public function findOffset(ObjectId $id): ?int
    {
        $this->ensureLoaded();

        return $this->entries[$id->hash] ?? null;
    }

    public function hasObject(ObjectId $id): bool
    {
        $this->ensureLoaded();

        return isset($this->entries[$id->hash]);
    }

    /**
     * @return list<ObjectId>
     */
    public function getAllIds(): array
    {
        $this->ensureLoaded();

        return array_map(ObjectId::fromHex(...), array_keys($this->entries));
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $data = file_get_contents($this->indexPath);
        if ($data === false) {
            throw new InvalidObjectException(sprintf('Cannot read pack index: %s', $this->indexPath));
        }

        $reader = new BinaryReader($data);

        $magic = $reader->readBytes(4);
        if ($magic !== "\xfftOc") {
            throw new InvalidObjectException('Invalid pack index magic');
        }

        $version = $reader->readUint32();
        if ($version !== 2) {
            throw new InvalidObjectException(sprintf('Unsupported pack index version: %d', $version));
        }

        $fanout = [];
        for ($i = 0; $i < 256; $i++) {
            $fanout[] = $reader->readUint32();
        }
        $totalObjects = $fanout[255];

        $hashes = [];
        for ($i = 0; $i < $totalObjects; $i++) {
            $hashes[] = bin2hex($reader->readBytes(20));
        }

        // Skip CRC32 values
        $reader->skip($totalObjects * 4);

        $offsets = [];
        for ($i = 0; $i < $totalObjects; $i++) {
            $offsets[] = $reader->readUint32();
        }

        for ($i = 0; $i < $totalObjects; $i++) {
            $this->entries[$hashes[$i]] = $offsets[$i];
        }

        $this->loaded = true;
    }
}
